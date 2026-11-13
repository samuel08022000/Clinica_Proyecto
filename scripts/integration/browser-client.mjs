// Minimal test-only CDP driver. Node 22 and an existing Chrome/Edge installation are required.
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
export const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

export class Browser {
    static async launch(output) {
        const candidates = [process.env.CHROME_PATH, join(process.env.PROGRAMFILES || '', 'Google/Chrome/Application/chrome.exe'), join(process.env['PROGRAMFILES(X86)'] || '', 'Microsoft/Edge/Application/msedge.exe'), '/usr/bin/google-chrome', '/usr/bin/chromium'];
        const executable = candidates.find(path => path && existsSync(path));
        if (!executable) throw new Error('Set CHROME_PATH to an installed Chrome/Edge executable.');
        const profile = resolve(output, 'chrome-' + Date.now());
        mkdirSync(profile, { recursive: true });
        const processHandle = spawn(executable, ['--headless=new','--remote-debugging-port=0',`--user-data-dir=${profile}`,'--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-features=Translate,MediaRouter','--disable-background-timer-throttling','--disable-renderer-backgrounding','--force-color-profile=srgb','about:blank'], { windowsHide: true, stdio: ['ignore','ignore','pipe'] });
        const address = await new Promise((resolve, reject) => {
            let buffer = '';
            const timer = setTimeout(() => reject(new Error('Browser startup timed out.')), 25000);
            processHandle.stderr.on('data', bytes => { buffer += bytes.toString(); const match = buffer.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timer); resolve(match[1]); } });
            processHandle.on('exit', () => { clearTimeout(timer); reject(new Error('Browser exited before CDP became ready.')); });
        });
        const driver = new Browser();
        driver.process = processHandle;
        driver.socket = new WebSocket(address);
        driver.pending = new Map(); driver.listeners = new Map(); driver.nextId = 1;
        await new Promise((resolve, reject) => { driver.socket.onopen = resolve; driver.socket.onerror = reject; });
        driver.socket.onmessage = event => {
            const message = JSON.parse(event.data);
            if (message.id && driver.pending.has(message.id)) {
                const task = driver.pending.get(message.id); driver.pending.delete(message.id); clearTimeout(task.timer);
                if (message.error) task.reject(new Error('CDP: ' + message.error.message)); else task.resolve(message.result);
            } else for (const listener of driver.listeners.get(`${message.sessionId || ''}|${message.method}`) || []) listener(message.params);
        };
        return driver;
    }
    send(method, params = {}, sessionId) {
        const id = this.nextId++;
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => { this.pending.delete(id); reject(new Error('CDP timed out: ' + method)); }, 30000);
            this.pending.set(id, { resolve, reject, timer });
            this.socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
        });
    }
    on(sessionId, method, listener) {
        const key = `${sessionId}|${method}`;
        if (!this.listeners.has(key)) this.listeners.set(key, []);
        this.listeners.get(key).push(listener);
    }
    async page() {
        const { browserContextId } = await this.send('Target.createBrowserContext');
        const { targetId } = await this.send('Target.createTarget', {url:'about:blank',browserContextId});
        const { sessionId } = await this.send('Target.attachToTarget', {targetId,flatten:true});
        const page = new Page(this, sessionId);
        await page.send('Page.enable'); await page.send('Runtime.enable'); await page.send('Network.enable');
        this.on(sessionId, 'Runtime.exceptionThrown', () => page.exceptions++);
        this.on(sessionId, 'Network.responseReceived', ({response,type}) => { if (type === 'Document') page.documents.push({path:new URL(response.url).pathname,status:response.status}); });
        await page.viewport(1440,900);
        return page;
    }
    async close() { try { await this.send('Browser.close'); } catch {} this.socket.close(); }
}
class Page {
    constructor(browser, sessionId) { this.browser=browser; this.sessionId=sessionId; this.exceptions=0; this.documents=[]; }
    send(method, params = {}) { return this.browser.send(method,params,this.sessionId); }
    async viewport(width,height) { await this.send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<600}); }
    async eval(fn, arg) {
        const expression = `(${fn.toString()})(${arg === undefined ? '' : JSON.stringify(arg)})`;
        const response = await this.send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
        if (response.exceptionDetails) throw new Error('Browser evaluation failed: '+(response.exceptionDetails.exception?.description?.split('\n')[0]||response.exceptionDetails.text));
        return response.result.value;
    }
    async goto(url) {
        const documentsBefore = this.documents.length;
        const result = await this.send('Page.navigate',{url});
        if (result.errorText) throw new Error('Navigation failed.');
        for(let attempt=0;attempt<150&&this.documents.length===documentsBefore;attempt++)await pause(100);
        if(this.documents.length===documentsBefore)throw new Error('The requested document did not arrive.');
        await this.wait(() => document.readyState === 'complete');
        await pause(80);
    }
    async wait(predicate, argument) {
        for(let attempt=0;attempt<150;attempt++) { try { if(await this.eval(predicate,argument))return; } catch {} await pause(100); }
        throw new Error('Expected browser state was not reached.');
    }
    async text() { return this.eval(() => document.body.innerText); }
    async screenshot(path) {
        const result = await this.send('Page.captureScreenshot',{format:'jpeg',quality:80,fromSurface:true,captureBeyondViewport:false});
        writeFileSync(path,Buffer.from(result.data,'base64'));
    }
}
