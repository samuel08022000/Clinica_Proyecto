// Transport fault injection is explicitly simulated. Authentication and healthy reads reach real Quarkus.
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, pause } from './browser-client.mjs';
if(process.env.RUN_CLINICA_E2E!=='1')throw new Error('Explicit opt-in required.');
const root=resolve('.'), output=resolve('backend_clinica/target/integration-fase12');
const fixture=JSON.parse(readFileSync(resolve(output,'browser-fixture.json'),'utf8'));
if(!/^clinica_fase12_e2e_[a-f0-9]{12}$/.test(fixture.database))throw new Error('Exclusive browser database required.');
let mode='healthy', writes=0, browser, php;
const checks=[];
const check=(label,value)=>{checks.push({label,status:value?'APROBADO CON SERVICIO SIMULADO':'FALLIDO'});writeFileSync(resolve(output,'fault-progress.json'),JSON.stringify(checks));if(!value)throw new Error(label);};
const proxy=createServer(async(req,res)=>{
    try{
        const path=new URL(req.url,'http://proxy').pathname;
        let body='';for await(const part of req)body+=part;
        if(path==='/api/v1/secretaria/citas'&&req.method==='GET'&&mode!=='healthy'){
            if(mode==='timeout'){await pause(1800);if(!res.destroyed){res.writeHead(503);res.end('{}');}return;}
            if(mode==='malformed'){res.writeHead(200,{'Content-Type':'application/json'});res.end('unreadable-json');return;}
            res.writeHead(Number(mode),{'Content-Type':'application/json'});res.end(JSON.stringify({mensaje:'Internal SQL password=never-show-this-marker'}));return;
        }
        if(path==='/api/v1/secretaria/checkin'&&req.method==='POST'){
            writes++;
            if(mode==='write-timeout'){await pause(1800);if(!res.destroyed){res.writeHead(503);res.end('{}');}return;}
            res.writeHead(500,{'Content-Type':'application/json'});res.end(JSON.stringify({mensaje:'Internal SQL password=never-show-this-marker'}));return;
        }
        const headers={'Accept':'application/json',...(req.headers.authorization?{Authorization:req.headers.authorization}:{}),...(body?{'Content-Type':'application/json'}:{})};
        const upstream=await fetch('http://127.0.0.1:18090'+req.url,{method:req.method,headers,...(body?{body}:{}),redirect:'manual'});
        res.writeHead(upstream.status,{'Content-Type':'application/json','Cache-Control':'no-store'});res.end(await upstream.text());
    }catch{if(!res.headersSent)res.writeHead(502);res.end('{}');}
});
await new Promise(resolve=>proxy.listen(0,'127.0.0.1',resolve));
const base='http://127.0.0.1:18093';
try{
    php=spawn('php',['-S','127.0.0.1:18093','-t',resolve('frontend_clinica/public'),resolve('frontend_clinica/tests/Integration/router.php')],{cwd:resolve('frontend_clinica'),windowsHide:true,stdio:'ignore',env:{...process.env,CLINICA_ISOLATED_HTTP:'1',CLINICA_RUNTIME_STORAGE:resolve(output,'browser-laravel'),APP_ENV:'local',APP_DEBUG:'false',APP_URL:base,APP_KEY:fixture.laravelKey,SESSION_DRIVER:'file',SESSION_ENCRYPT:'true',SESSION_COOKIE:'clinica_fase12_fault_session',SESSION_SECURE_COOKIE:'false',CACHE_STORE:'file',QUARKUS_API_URL:`http://127.0.0.1:${proxy.address().port}/api/v1`,QUARKUS_API_TIMEOUT:'1',QUARKUS_API_CONNECT_TIMEOUT:'1'}});
    for(let i=0;i<50;i++){try{if((await fetch(base+'/login')).ok)break;}catch{}await pause(100);}
    browser=await Browser.launch(output);
    const page=await browser.page();await page.goto(base+'/login');
    await page.eval(user=>{const form=document.querySelector('form[action$="/login"]');form.querySelector('[name=email]').value=user.email;form.querySelector('[name=password]').value=user.password;form.requestSubmit();},fixture.users.ROL002);
    await page.wait(()=>location.pathname==='/secretaria/inicio');
    for(const fault of['500','502','503','timeout','malformed']){
        mode=fault;await page.goto(base+'/secretaria/inicio');
        check(fault+': recoverable response without internal details',[502,503].includes(page.documents.at(-1)?.status)&&!await page.eval(()=>document.body.innerText.includes('never-show-this-marker')));
        mode='healthy';await page.goto(base+'/secretaria/inicio');
        check(fault+': same authorized session recovers',page.documents.at(-1)?.status===200&&await page.eval(()=>document.body.innerText.includes('Panel de Secretaría')));
    }
    for(const fault of['500','write-timeout']){
        mode=fault;await page.goto(base+'/secretaria/checkin');
        const before=writes;
        await page.eval(token=>{document.querySelector('[name=tokenQr]').value=token;document.querySelector('[data-admin-form]').requestSubmit();},fixture.tokenQr);
        await page.wait(()=>!!document.querySelector('[role=alert]'));
        check(fault+': no automatic write retry',writes===before+1);
        check(fault+': QR cleared and failure not announced as success',await page.eval(()=>document.querySelector('[name=tokenQr]').value===''&&!document.body.innerText.includes('Check-in registrado')&&!document.body.innerText.includes('never-show-this-marker')));
    }
    await page.screenshot(resolve(output,'screens','fault-recovery.jpg'));
    check('No JavaScript exception during recovery',page.exceptions===0);
}catch(error){checks.push({label:error.message,status:'FALLIDO'});}
finally{
    writeFileSync(resolve(output,'fault-e2e.json'),JSON.stringify({source:'Real Chrome/Laravel; real Quarkus auth; explicitly simulated upstream failures',checks},null,2));
    if(browser)await browser.close();if(php)php.kill();proxy.closeAllConnections();proxy.close();
}
console.log(JSON.stringify({checks:checks.length,passed:checks.filter(c=>c.status!=='FALLIDO').length,failed:checks.filter(c=>c.status==='FALLIDO').length}));
process.exitCode=checks.some(c=>c.status==='FALLIDO')?1:0;
