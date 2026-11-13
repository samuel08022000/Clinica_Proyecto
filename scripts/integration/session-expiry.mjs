// A separate real JVM uses a one-minute session TTL; no operating-system clock is changed.
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, openSync, closeSync } from 'node:fs';
import { resolve } from 'node:path';
import { createServer } from 'node:net';
import { pause } from './browser-client.mjs';
if(process.env.RUN_CLINICA_E2E!=='1')throw new Error('Explicit opt-in required.');
const root=resolve('.'), output=resolve('backend_clinica/target/integration-fase12');
const state=JSON.parse(readFileSync(resolve(output,'runtime.json'),'utf8'));
const fixture=JSON.parse(readFileSync(resolve(output,'browser-fixture.json'),'utf8'));
if(state.project!=='clinica-fase12'||fixture.database!==state.browser||!/^clinica_fase12_e2e_[a-f0-9]{12}$/.test(fixture.database))throw new Error('Owned browser database required.');
for(const port of[18094,18095]){const probe=createServer();await new Promise((resolve,reject)=>{probe.once('error',reject);probe.listen(port,'127.0.0.1',resolve);});await new Promise(resolve=>probe.close(resolve));}
const checks=[];let backend,frontend,backendLog;
const check=(label,value)=>{checks.push({label,status:value?'APROBADO CON BACKEND REAL':'FALLIDO'});writeFileSync(resolve(output,'expiry-progress.json'),JSON.stringify(checks));if(!value)throw new Error(label);};
try{
    backendLog=openSync(resolve(output,'expiry-backend.log'),'w');
    backend=spawn('java',['-jar',resolve('backend_clinica/target/quarkus-app/quarkus-run.jar')],{cwd:resolve('backend_clinica'),windowsHide:true,stdio:['ignore',backendLog,backendLog],env:{...process.env,QUARKUS_PROFILE:'integration',QUARKUS_HTTP_HOST:'127.0.0.1',QUARKUS_HTTP_PORT:'18094',QUARKUS_DATASOURCE_JDBC_URL:`jdbc:postgresql://127.0.0.1:35441/${state.browser}`,QUARKUS_DATASOURCE_USERNAME:state.user,QUARKUS_DATASOURCE_PASSWORD:state.password,QUARKUS_DATASOURCE_DEVSERVICES_ENABLED:'false',QUARKUS_COMPOSE_DEVSERVICES_ENABLED:'false',QUARKUS_FLYWAY_LOCATIONS:'classpath:db/migration',QUARKUS_FLYWAY_BASELINE_ON_MIGRATE:'false',QUARKUS_FLYWAY_OUT_OF_ORDER:'false',QUARKUS_SCHEDULER_ENABLED:'false',QUARKUS_HIBERNATE_ORM_LOG_SQL:'false',OTP_DELIVERY_PROVIDER:'disabled',GOOGLE_OAUTH_ENABLED:'false',AUTH_SESSION_TTL_MINUTES:'1'}});
    let ready=false;for(let i=0;i<90;i++){try{if((await fetch('http://127.0.0.1:18094/hello',{signal:AbortSignal.timeout(1000)})).ok){ready=true;break;}}catch{}await pause(500);}check('Separate real Quarkus instance ready',ready);
    const storage=resolve(output,'expiry-laravel');for(const path of['framework/sessions','framework/views','framework/cache/data','logs'])mkdirSync(resolve(storage,path),{recursive:true});
    const base='http://127.0.0.1:18095';
    frontend=spawn('php',['-S','127.0.0.1:18095','-t',resolve('frontend_clinica/public'),resolve('frontend_clinica/tests/Integration/router.php')],{cwd:resolve('frontend_clinica'),windowsHide:true,stdio:'ignore',env:{...process.env,CLINICA_ISOLATED_HTTP:'1',CLINICA_RUNTIME_STORAGE:storage,APP_ENV:'local',APP_DEBUG:'false',APP_URL:base,APP_KEY:fixture.laravelKey,SESSION_DRIVER:'file',SESSION_ENCRYPT:'true',SESSION_COOKIE:'clinica_fase12_expiry_session',SESSION_SECURE_COOKIE:'false',CACHE_STORE:'file',QUARKUS_API_URL:'http://127.0.0.1:18094/api/v1'}});
    for(let i=0;i<40;i++){try{if((await fetch(base+'/login')).ok)break;}catch{}await pause(200);}
    const cookies=new Map();
    const http=async(path,options={})=>{
        const response=await fetch(base+path,{...options,redirect:'manual',headers:{...options.headers,Cookie:[...cookies].map(([key,value])=>key+'='+value).join('; ')}});
        for(const header of response.headers.getSetCookie()){const pair=header.split(';')[0];const at=pair.indexOf('=');cookies.set(pair.slice(0,at),pair.slice(at+1));}
        return {status:response.status,location:response.headers.get('location'),html:await response.text()};
    };
    const form=await http('/login');
    const csrf=form.html.match(/name="_token" value="([^"]+)"/)[1];
    const loggedIn=await http('/login',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_token:csrf,email:fixture.users.ROL002.email,password:fixture.users.ROL002.password})});
    check('Laravel HTTP login uses the real short-lived session',loggedIn.status===302&&loggedIn.location.endsWith('/secretaria/inicio')&&(await http('/secretaria/inicio')).status===200);
    const login=await fetch('http://127.0.0.1:18094/api/v1/auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:fixture.users.ROL003.email,password:fixture.users.ROL003.password})});
    const payload=await login.json();const token=payload.data.session.accessToken;
    const remaining=Date.parse(payload.data.session.expiresAt)-Date.now();check('Configured TTL is one real minute',remaining>45000&&remaining<=61000);
    // Bounded waits keep the process observable via expiry-progress.json, without changing any clock.
    await pause(30000);await pause(30000);await pause(5000);
    const expired=await fetch('http://127.0.0.1:18094/api/v1/auth/me',{headers:{Authorization:'Bearer '+token}});check('Real expired Bearer rejected',expired.status===401);
    const redirected=await http('/secretaria/inicio');
    check('Laravel clears the expired session and requires login',redirected.status===302&&redirected.location.endsWith('/login')&&(await http('/login')).html.includes('expiró o fue revocada'));
}catch(error){checks.push({label:error.message,status:'FALLIDO'});}
finally{writeFileSync(resolve(output,'session-expiry.json'),JSON.stringify({source:'Real packaged Quarkus, PostgreSQL and Laravel HTTP/cookies/CSRF; test-only one-minute TTL; no browser claim',checks},null,2));if(frontend)frontend.kill();if(backend)backend.kill();if(backendLog!==undefined)closeSync(backendLog);}
console.log(JSON.stringify({checks:checks.length,passed:checks.filter(c=>c.status!=='FALLIDO').length,failed:checks.filter(c=>c.status==='FALLIDO').length}));
process.exitCode=checks.some(c=>c.status==='FALLIDO')?1:0;
