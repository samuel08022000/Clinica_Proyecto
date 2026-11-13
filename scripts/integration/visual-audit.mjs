// Final visual review: production-mode Laravel renders real persisted test data through Quarkus.
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, pause } from './browser-client.mjs';
if(process.env.RUN_CLINICA_E2E!=='1')throw new Error('Explicit opt-in required.');
const root=resolve('.'), output=resolve('backend_clinica/target/integration-fase12');
const fixture=JSON.parse(readFileSync(resolve(output,'browser-fixture.json'),'utf8'));
const clinical=JSON.parse(readFileSync(resolve(output,'browser-e2e.json'),'utf8'));
if(fixture.database!==clinical.database||!/^clinica_fase12_e2e_[a-f0-9]{12}$/.test(fixture.database))throw new Error('Owned browser dataset required.');
const visual=resolve(output,'visual-live'), storage=resolve(output,'visual-laravel');
for(const path of['framework/sessions','framework/views','framework/cache/data','logs'])mkdirSync(resolve(storage,path),{recursive:true});
mkdirSync(visual,{recursive:true});mkdirSync(resolve(visual,'snapshots'),{recursive:true});
const base='http://127.0.0.1:18096';let php,browser;
const results=[];
const check=(label,passed)=>{results.push({label,passed:!!passed});writeFileSync(resolve(visual,'progress.json'),JSON.stringify(results));if(!passed)throw new Error(label);};
const evidence=clinical.evidence;
const screens=[
    ['ROL004','admin-dashboard','/administracion/inicio'],
    ['ROL004','admin-medicos','/administracion/medicos'],
    ['ROL004','admin-medico-formulario','/administracion/medicos/crear'],
    ['ROL004','admin-especialidades','/administracion/especialidades'],
    ['ROL004','admin-especialidad-formulario','/administracion/especialidades/crear'],
    ['ROL004','admin-agenda',`/administracion/agendas?medico=${evidence.doctorCode}`],
    ['ROL004','admin-bloqueo-formulario',`/administracion/bloqueos/medicos/${evidence.doctorCode}/crear`],
    ['ROL004','admin-bloqueo',`/administracion/bloqueos/${evidence.blockCode}`],
    ['ROL004','admin-paciente',`/administracion/pacientes/${fixture.users.ROL001.codPac}`],
    ['ROL004','admin-sancion',`/administracion/pacientes/${fixture.sanctionPatient.codPac}/sanciones/${evidence.sanctionCode}`],
    ['ROL002','secretaria-dashboard','/secretaria/inicio'],
    ['ROL002','secretaria-pacientes','/secretaria/pacientes?q=Elena'],
    ['ROL002','secretaria-paciente',`/secretaria/pacientes/${fixture.users.ROL001.codPac}`],
    ['ROL002','secretaria-disponibilidad',`/secretaria/reservas/nueva?codPac=${fixture.users.ROL001.codPac}&codEsp=${fixture.codEsp}&codMed=${evidence.doctorCode}&fecha=${fixture.fecha}`],
    ['ROL002','secretaria-citas',`/secretaria/citas?fecha=${fixture.fecha}`],
    ['ROL002','secretaria-qr','/secretaria/checkin'],
    ['ROL002','secretaria-asistencia',`/secretaria/citas/${fixture.codCitQr}/asistencia`],
    ['ROL002','secretaria-historial',`/secretaria/pacientes/${fixture.historyPatient.codPac}/historial`],
    ['ROL002','secretaria-historial-vacio',`/secretaria/pacientes/${fixture.sanctionPatient.codPac}/historial`],
    ['ROL002','secretaria-busqueda-vacia','/secretaria/pacientes?q=SinCoincidenciasDoce'],
    ['ROL002','secretaria-restringido',`/secretaria/pacientes/${fixture.sanctionPatient.codPac}`],
    ['ROL002','secretaria-error','/secretaria/citas/CIT99999999'],
];
try{
    php=spawn('php',['-S','127.0.0.1:18096','-t',resolve('frontend_clinica/public'),resolve('frontend_clinica/tests/Integration/router.php')],{cwd:resolve('frontend_clinica'),windowsHide:true,stdio:'ignore',env:{...process.env,CLINICA_ISOLATED_HTTP:'1',CLINICA_RUNTIME_STORAGE:storage,APP_ENV:'production',APP_DEBUG:'false',APP_URL:base,APP_KEY:fixture.laravelKey,SESSION_DRIVER:'file',SESSION_ENCRYPT:'true',SESSION_COOKIE:'clinica_fase12_visual_session',SESSION_SECURE_COOKIE:'false',CACHE_STORE:'file',QUARKUS_API_URL:fixture.apiUrl}});
    for(let i=0;i<50;i++){try{const response=await fetch(base+'/login');await response.text();if(response.ok)break;}catch{}await pause(200);}
    browser=await Browser.launch(output);const pages={};
    for(const role of['ROL004','ROL002']){
        const page=await browser.page();pages[role]=page;await page.goto(base+'/login');
        await page.wait(()=>!!document.querySelector('form[action$="/login"]'));
        await page.eval(user=>{const form=document.querySelector('form[action$="/login"]');form.querySelector('[name=email]').value=user.email;form.querySelector('[name=password]').value=user.password;form.requestSubmit();},fixture.users[role]);
        await page.wait(()=>location.pathname.endsWith('/inicio'));
    }
    for(const[role,name,path]of screens){
        const page=pages[role];await page.goto(base+path);await page.wait(()=>!!document.querySelector('h1'));
        await page.eval(async()=>{await document.fonts.load('400 16px "Instrument Sans"');await document.fonts.ready;});
        const html=await page.eval(()=>document.documentElement.outerHTML);
        check(name+': production response without development logger',!html.includes('browser-logger-active')&&!html.includes(fixture.tokenQr));
        // Only the asset origin is adapted for the offline inspector after this short-lived server exits.
        writeFileSync(resolve(visual,'snapshots',name+'.html'),html.replace(/(?:https?:\/\/[^/\s"'()]+)?\/build\//g,'/frontend_clinica/public/build/'));
        for(const[width,height]of[[1440,900],[768,1024],[390,844]]){
            await page.viewport(width,height);await pause(60);
            const metrics=await page.eval(()=>({overflow:document.documentElement.scrollWidth>innerWidth+1,h1:document.querySelectorAll('h1').length,font:document.fonts.check('400 16px "Instrument Sans"'),labels:[...document.querySelectorAll('input:not([type=hidden]),select,textarea')].every(field=>field.labels?.length||field.getAttribute('aria-label'))}));
            check(name+' '+width+'px',!metrics.overflow&&metrics.h1===1&&metrics.font&&metrics.labels);
            await page.screenshot(resolve(visual,name+'-'+width+'.jpg'));
        }
        await page.viewport(1440,900);
    }
    const mobile=pages.ROL002;await mobile.goto(base+'/secretaria/inicio');await mobile.viewport(390,844);
    await mobile.eval(()=>{const button=document.querySelector('[data-open-admin-menu]');button.focus();button.click();});
    check('Mobile menu receives keyboard focus',await mobile.eval(()=>document.querySelector('#admin-mobile-menu').contains(document.activeElement)));
    await mobile.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await mobile.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    check('Escape closes menu and restores focus',await mobile.eval(()=>!document.querySelector('#admin-mobile-menu').open&&document.activeElement.matches('[data-open-admin-menu]')));
    await mobile.send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});await mobile.viewport(844,390);
    check('Reduced-motion landscape remains usable',await mobile.eval(()=>matchMedia('(prefers-reduced-motion:reduce)').matches&&document.documentElement.scrollWidth<=innerWidth+1));
    const admin=pages.ROL004;await admin.goto(base+screens.find(s=>s[1]==='admin-bloqueo-formulario')[2]);await admin.viewport(390,844);
    check('Block form exposes corrected maximum',await admin.eval(()=>document.querySelector('[name=motivo]').maxLength===500));
    await admin.eval(date=>{const form=document.querySelector('[data-admin-form]');form.querySelector('[name=fechaBloqueo]').value=date;form.querySelector('[name=motivo]').value='Comprobacion de confirmacion, sin guardar';form.querySelector('[name=confirmacion]').checked=true;const button=form.querySelector('[type=submit]');button.focus();form.requestSubmit(button);},fixture.fecha);
    check('Sensitive confirmation receives keyboard focus',await admin.eval(()=>document.querySelector('[data-admin-confirm]').open&&document.querySelector('[data-admin-confirm]').contains(document.activeElement)));
    await admin.screenshot(resolve(visual,'confirmation-mobile.jpg'));
    await admin.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await admin.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    check('Escape cancels confirmation without posting',await admin.eval(()=>!document.querySelector('[data-admin-confirm]').open&&location.pathname.endsWith('/crear')));
    check('No JavaScript exceptions in final review',Object.values(pages).every(page=>page.exceptions===0));
}catch(error){results.push({label:error.message,passed:false});}
finally{writeFileSync(resolve(visual,'report.json'),JSON.stringify({source:'Production-mode Laravel -> real Quarkus -> exclusive PostgreSQL',screens:screens.map(s=>({name:s[1],path:s[2]})),results},null,2));if(browser)await browser.close();if(php)php.kill();}
console.log(JSON.stringify({checks:results.length,passed:results.filter(r=>r.passed).length,failed:results.filter(r=>!r.passed).length}));process.exitCode=results.some(r=>!r.passed)?1:0;
