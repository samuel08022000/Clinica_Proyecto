import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, pause } from './browser-client.mjs';

if (process.env.RUN_CLINICA_E2E !== '1') throw new Error('Explicit RUN_CLINICA_E2E=1 is required.');
const root = resolve('.');
const output = resolve(root,'backend_clinica/target/integration-fase12');
const fixture = JSON.parse(readFileSync(resolve(output,'browser-fixture.json'),'utf8'));
if (!/^clinica_fase12_e2e_[a-f0-9]{12}$/.test(fixture.database) || fixture.apiUrl !== 'http://127.0.0.1:18090/api/v1' || fixture.laravelUrl !== 'http://127.0.0.1:18091') throw new Error('Only the isolated Fase 12 fixture is permitted.');
const base = fixture.laravelUrl;
const results = [], snapshots = [], evidence = {};
let browser, currentPage;
mkdirSync(resolve(output,'screens'),{recursive:true});
mkdirSync(resolve(output,'snapshots'),{recursive:true});
const check = (label, value, kind='APROBADO CON BACKEND REAL') => {
    results.push({label,status:value?kind:'FALLIDO'});
    // Persist progress instead of flooding a nested Windows stdout pipe during long browser runs.
    writeFileSync(resolve(output,'browser-progress.json'),JSON.stringify({results,evidence,snapshots},null,2));
    if(!value)throw new Error(label);
};
const api = async (path, token, method='GET', data) => {
    const response = await fetch(fixture.apiUrl+path,{method,headers:{'Accept':'application/json',...(token?{Authorization:'Bearer '+token}:{}),...(data!==undefined?{'Content-Type':'application/json'}:{})},...(data!==undefined?{body:JSON.stringify(data)}:{})});
    let body; try {body=await response.json();}catch{body=null;}
    return {status:response.status,body};
};
const apiLogin = async user => {const response=await api('/auth/login',null,'POST',{email:user.email,password:user.password});if(response.status!==200)throw new Error('Isolated API login failed.');return response.body.data.session.accessToken;};
const go = async (page,path,text) => { currentPage=page; await page.goto(base+path);if(text)await page.wait(expected=>document.body.innerText.includes(expected),text); };
const submit = async (page,values,selector='[data-admin-form]') => {
    await page.eval(({values,selector})=>{
        const form=document.querySelector(selector); if(!form)throw new Error('Missing test form');
        for(const detail of form.querySelectorAll('details'))detail.open=true;
        const ancestor=form.closest('details'); if(ancestor)ancestor.open=true;
        for(const [name,value]of Object.entries(values)){
            const fields=[...form.querySelectorAll(`[name="${name}"]`)]; if(!fields.length)throw new Error('Missing test field');
            for(const field of fields){if(field.type==='checkbox')field.checked=Array.isArray(value)?value.includes(field.value):!!value;else field.value=value;field.dispatchEvent(new Event('input',{bubbles:true}));}
        }
        form.requestSubmit(form.querySelector('[type=submit]')||undefined);
        const dialog=document.querySelector('[data-admin-confirm]');if(dialog?.open)dialog.querySelector('[data-confirm-accept]').click();
    },{values,selector});
    await pause(200);
};
const waitPath = (page,path) => page.wait(expected=>location.pathname===expected&&document.readyState==='complete',path);
const saved = async page => page.wait(()=>!!document.querySelector('.a-notice-success')||document.body.innerText.includes('Operación realizada')||document.body.innerText.includes('guardado correctamente'));
const capture = async (page,name) => {
    await page.eval(async()=>{await document.fonts.load('400 16px "Instrument Sans"');await document.fonts.ready;});
    const html=await page.eval(()=>document.documentElement.outerHTML);
    check(name+': no credentials or QR in rendered HTML',![fixture.tokenQr,...Object.values(fixture.users).map(u=>u.password)].some(secret=>html.includes(secret)));
    writeFileSync(resolve(output,'snapshots',name+'.html'),html);
    snapshots.push({name,file:`snapshots/${name}.html`});
    for(const [width,height]of[[1440,900],[768,1024],[390,844]]){
        await page.viewport(width,height); await pause(80);
        const metrics=await page.eval(()=>({overflow:document.documentElement.scrollWidth>innerWidth+1,headings:document.querySelectorAll('h1').length,missingLabels:[...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(e=>!e.labels?.length&&!e.getAttribute('aria-label')).length,fonts:document.fonts.check('400 16px "Instrument Sans"')}));
        check(`${name} ${width}px: overflow, title, labels and font`,!metrics.overflow&&metrics.headings===1&&metrics.missingLabels===0&&metrics.fonts);
        await page.screenshot(resolve(output,'screens',`${name}-${width}.jpg`));
    }
    await page.viewport(1440,900);
};
const login = async (page,user,home) => {
    await go(page,'/login','Iniciar');
    const before=(await page.send('Network.getCookies')).cookies.find(c=>c.name==='clinica_fase12_session')?.value;
    await submit(page,{email:user.email,password:user.password},'form[action$="/login"]');
    await waitPath(page,home);
    const cookies=(await page.send('Network.getCookies')).cookies;
    const session=cookies.find(c=>c.name==='clinica_fase12_session');
    check('Real login, session regeneration, HttpOnly and SameSite: '+home,!!session&&session.httpOnly&&session.sameSite==='Lax'&&session.value!==before);
};
try {
    // Read-only readiness checks prevent a cold JVM/PHP bootstrap from being mistaken for a failed form.
    for(const url of ['http://127.0.0.1:18090/api/v1/auth/me',base+'/login']){
        let ready=false;
        for(let attempt=0;attempt<20;attempt++){try{const response=await fetch(url,{signal:AbortSignal.timeout(8000)});await response.text();if(response.status===(url.endsWith('/auth/me')?401:200)){ready=true;break;}}catch{}await pause(500);}
        if(!ready)throw new Error('Isolated applications did not become ready.');
    }
    browser=await Browser.launch(output);
    const roles={};
    for(const[role,home]of[['ROL001','/paciente/inicio'],['ROL002','/secretaria/inicio'],['ROL003','/medico/inicio'],['ROL004','/administracion/inicio']]){
        const page=await browser.page();roles[role]=page;await login(page,fixture.users[role],home);
        for(const other of['/paciente/inicio','/secretaria/inicio','/medico/inicio','/administracion/inicio'].filter(path=>path!==home)){
            await go(page,other);check(role+' denied '+other,page.documents.at(-1)?.status===403);
        }
        await go(page,home);
    }
    const admin=roles.ROL004, secretary=roles.ROL002;
    evidence.initialRoles=Object.keys(roles);
    await capture(admin,'admin-dashboard');
    await capture(secretary,'secretaria-dashboard');
    const patientPage=roles.ROL001;
    await submit(patientPage,{},'form[action$="/logout"]');await waitPath(patientPage,'/');
    await go(patientPage,'/paciente/inicio');check('Logout prevents reentry',await patientPage.eval(()=>location.pathname==='/login'));
    const noCsrf=await fetch(base+'/secretaria/checkin',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'tokenQr=not-a-valid-qr'});
    check('Actual HTTP CSRF rejection',noCsrf.status===419);
    // Login deliberately revokes prior sessions for the same user. Observers have separate real accounts.
    const adminToken=await apiLogin(fixture.observerAdmin), secretaryToken=await apiLogin(fixture.observerSecretary);
    check('Quarkus independently denies administrator on secretary API',(await api('/secretaria/citas',adminToken)).status===403);
    check('Quarkus independently denies secretary on admin API',(await api('/personal/medicos',secretaryToken)).status===403);
    check('Invalid opaque Bearer denied',(await api('/auth/me','invalid-fase12-token')).status===401);
    const revoked=await apiLogin(fixture.users.ROL003);await api('/auth/logout',revoked,'POST',{});check('Revoked opaque Bearer denied',(await api('/auth/me',revoked)).status===401);
    await go(roles.ROL003,'/medico/inicio');
    check('Remote revocation returns the existing browser session to login',await roles.ROL003.eval(()=>location.pathname==='/login'));
    check('Incorrect password rejected',(await api('/auth/login',null,'POST',{email:fixture.users.ROL001.email,password:'Incorrect-fase12-only'})).status===401);

    await go(admin,'/administracion/especialidades','Especialidades');
    await go(admin,'/administracion/especialidades/crear','Nueva especialidad');
    await submit(admin,{nombreEspecialidad:'Especialidad E2E Doce',descripcion:'Control <script>window.__f12Xss=1</script>',duracionConsultaMin:'30'});
    await waitPath(admin,'/administracion/especialidades');
    const specialtyCode=(await api('/personal/especialidades?estado=Todos',adminToken)).body.find(row=>row.nombreEspecialidad==='Especialidad E2E Doce').codEsp;evidence.specialtyCode=specialtyCode;
    await go(admin,`/administracion/especialidades/${specialtyCode}`);
    check('Create specialty through Blade', (await api('/personal/especialidades/'+specialtyCode,adminToken)).body?.duracionConsultaMin===30);
    check('Persisted text is HTML-escaped',await admin.eval(()=>!window.__f12Xss&&document.body.innerText.includes('<script>')));
    await go(admin,`/administracion/especialidades/${specialtyCode}/editar`,'Editar especialidad');
    await submit(admin,{nombreEspecialidad:'Especialidad E2E Doce Editada',descripcion:'Especialidad exclusiva de auditoria E2E'});
    await waitPath(admin,`/administracion/especialidades/${specialtyCode}`);
    check('Edit specialty through Blade',(await admin.text()).includes('Editada'));
    await capture(admin,'admin-especialidad');

    const suffix=fixture.database.slice(-12);
    const doctorEmail=`medico-${suffix}@example.test`;
    await go(admin,'/administracion/medicos','Médicos');await capture(admin,'admin-medicos');
    await go(admin,'/administracion/medicos/crear','Registrar médico');await capture(admin,'admin-medico-formulario');
    await submit(admin,{nombreCompleto:'Doctor Creado E2E Doce',email:doctorEmail,ci:'MED'+suffix,matriculaProfesional:'MAT'+suffix,duracionConsultaMin:'30',resumenCurricular:'Profesional ficticio para integracion',password:fixture.staffPassword,password_confirmation:fixture.staffPassword,'codigosEspecialidades[]':[specialtyCode,fixture.codEsp]});
    await waitPath(admin,'/administracion/medicos');
    const doctorCode=(await api('/personal/medicos',adminToken)).body.find(row=>row.email===doctorEmail).codMed;evidence.doctorCode=doctorCode;
    await go(admin,`/administracion/medicos/${doctorCode}`);
    check('Create doctor and two specialty links',(await api('/personal/medicos/'+doctorCode,adminToken)).body?.especialidades?.length===2);
    await go(admin,`/administracion/medicos/${doctorCode}/editar`,'Editar médico');
    await submit(admin,{nombreCompleto:'Doctor Editado E2E Doce',matriculaProfesional:'EDIT'+suffix});await waitPath(admin,`/administracion/medicos/${doctorCode}`);
    check('Edit doctor through Blade',(await admin.text()).includes('Doctor Editado'));
    await submit(admin,{confirmacion:true},'form[action$="/estado"]');await waitPath(admin,`/administracion/medicos/${doctorCode}`);await admin.wait(()=>document.querySelector('input[name=estado]')?.value==='Activo');
    check('Inactivate doctor',(await api('/personal/medicos/'+doctorCode,adminToken)).body?.estado==='Inactivo');
    await submit(admin,{confirmacion:true},'form[action$="/estado"]');await admin.wait(()=>document.querySelector('input[name=estado]')?.value==='Inactivo');
    check('Reactivate doctor',(await api('/personal/medicos/'+doctorCode,adminToken)).body?.estado==='Activo');

    const staffEmail=`recepcion-${suffix}@example.test`;
    await go(admin,'/administracion/secretarias/crear','Registrar secretario');
    await submit(admin,{nombreCompleto:'Secretario Creado E2E Doce',email:staffEmail,ci:'SEC'+suffix,telefono:'70000001',password:fixture.staffPassword,password_confirmation:fixture.staffPassword});
    await waitPath(admin,'/administracion/secretarias');
    const staffCode=(await api('/personal/secretarias',adminToken)).body.find(row=>row.email===staffEmail).codSec;evidence.staffCode=staffCode;
    await go(admin,`/administracion/secretarias/${staffCode}`);
    check('Create secretary through Blade',(await api('/personal/secretarias/'+staffCode,adminToken)).body?.email===staffEmail);
    await go(admin,`/administracion/secretarias/${staffCode}/editar`,'Editar secretario');
    await submit(admin,{nombreCompleto:'Secretario Editado E2E Doce',telefono:'70000002'});await waitPath(admin,`/administracion/secretarias/${staffCode}`);
    check('Edit secretary through Blade',(await api('/personal/secretarias/'+staffCode,adminToken)).body?.telefono==='70000002');
    const staffToken=await apiLogin({email:staffEmail,password:fixture.staffPassword});
    await submit(admin,{confirmacion:true},'form[action$="/estado"]');await admin.wait(()=>document.querySelector('input[name=estado]')?.value==='Activo');
    check('Inactivation revokes staff sessions',(await api('/auth/me',staffToken)).status===401);
    await submit(admin,{confirmacion:true},'form[action$="/estado"]');await admin.wait(()=>document.querySelector('input[name=estado]')?.value==='Inactivo');
    const resetToken=await apiLogin({email:staffEmail,password:fixture.staffPassword});
    await go(admin,`/administracion/secretarias/${staffCode}/password`,'Restablecer contraseña');
    const newPassword='New'+fixture.staffPassword;
    await submit(admin,{password:newPassword,password_confirmation:newPassword,confirmacion:true});await waitPath(admin,`/administracion/secretarias/${staffCode}`);
    check('Password reset 204 accepted and sessions revoked',(await api('/auth/me',resetToken)).status===401);
    check('Reset password permits real login',!!await apiLogin({email:staffEmail,password:newPassword}));

    await go(admin,`/administracion/agendas/${doctorCode}/horarios/crear`,'Agregar horario');
    await submit(admin,{diaSemana:fixture.weekday,horaInicio:'08:00',horaFin:'12:00',pausaInicio:'10:00',pausaFin:'10:30'});
    await waitPath(admin,'/administracion/agendas');
    let schedules=(await api(`/administracion/medicos/${doctorCode}/horarios`,adminToken)).body;
    check('Create weekly schedule through Blade',Array.isArray(schedules)&&schedules.length===1);
    const scheduleCode=schedules[0].codHor;evidence.scheduleCode=scheduleCode;
    await go(admin,`/administracion/agendas/${doctorCode}/horarios/${scheduleCode}/editar`,'Editar horario');
    await submit(admin,{horaFin:'13:00'});await waitPath(admin,'/administracion/agendas');
    check('Edit weekly schedule',(await api(`/administracion/medicos/${doctorCode}/horarios`,adminToken)).body?.[0]?.horaFin.startsWith('13:00'));
    await submit(admin,{duracionConsultaMin:'20',confirmacion:true},'form[action$="/duracion"]');
    await admin.wait(()=>document.body.innerText.includes('Actual: 20 minutos'));
    check('Configure doctor duration',(await api('/personal/medicos/'+doctorCode,adminToken)).body?.duracionConsultaMin===20);
    await capture(admin,'admin-agenda');
    await go(admin,`/administracion/agendas/${doctorCode}/horarios/crear`,'Agregar horario');
    await submit(admin,{diaSemana:fixture.weekday,horaInicio:'09:00',horaFin:'11:00'});
    await admin.wait(()=>!!document.querySelector('[role=alert]'));
    check('Overlapping schedules rejected without persistence',(await api(`/administracion/medicos/${doctorCode}/horarios`,adminToken)).body?.length===1);
    await capture(admin,'admin-horario-conflicto');

    await go(secretary,'/secretaria/pacientes','Buscar pacientes');
    await submit(secretary,{q:fixture.users.ROL001.ci},'form.a-filter');await secretary.wait(name=>document.body.innerText.includes(name),fixture.users.ROL001.name);
    check('Patient search by CI through Blade',(await secretary.text()).includes(fixture.users.ROL001.ci));
    await submit(secretary,{q:'Elena Paciente'},'form.a-filter');await secretary.wait(name=>document.body.innerText.includes(name),fixture.users.ROL001.name);
    check('Patient search by name through Blade',(await secretary.text()).includes(fixture.users.ROL001.ci));
    await capture(secretary,'secretaria-pacientes');
    await go(secretary,`/secretaria/pacientes/${fixture.users.ROL001.codPac}`,fixture.users.ROL001.name);await capture(secretary,'secretaria-paciente');
    check('Profile exposes backend booking eligibility',(await secretary.text()).includes('habilitado para reservar'));
    const browse=`/secretaria/reservas/nueva?codPac=${fixture.users.ROL001.codPac}&codEsp=${fixture.codEsp}&codMed=${doctorCode}&fecha=${fixture.fecha}`;
    await go(secretary,browse,'Seleccionar turno');await capture(secretary,'secretaria-disponibilidad');
    const selectedUrl=await secretary.eval(()=>document.querySelector('.r-slot').href);
    const selectedSlot=new URL(selectedUrl).searchParams.get('codSlot');evidence.reservedSlot=selectedSlot;
    await secretary.goto(selectedUrl);await secretary.wait(()=>document.body.innerText.includes('Resumen de la reserva'));await capture(secretary,'secretaria-confirmacion');
    await submit(secretary,{confirmacion:true});await secretary.wait(()=>document.body.innerText.includes('Reserva confirmada por el servicio'));
    const appointment=await secretary.eval(()=>location.pathname.split('/').at(-1));evidence.reservedAppointment=appointment;
    const booked=(await api('/secretaria/citas/'+appointment,secretaryToken)).body;
    check('Blade reservation persisted with Ventanilla and authenticated secretary',booked?.codSlot===selectedSlot&&booked.canalReserva==='Ventanilla'&&booked.codSec===fixture.users.ROL002.codSec);
    await capture(secretary,'secretaria-reserva-exitosa');

    await go(admin,`/administracion/agendas?medico=${doctorCode}`,'Duración general');
    await submit(admin,{duracionConsultaMin:'45',confirmacion:true},'form[action$="/duracion"]');await admin.wait(()=>!!document.querySelector('[role=alert]'));
    check('Incompatible duration rejected with future appointment',(await api('/personal/medicos/'+doctorCode,adminToken)).body?.duracionConsultaMin===20);
    await go(admin,`/administracion/agendas/${doctorCode}/horarios/${scheduleCode}/editar`,'Editar horario');
    await submit(admin,{horaInicio:'09:00'});await admin.wait(()=>!!document.querySelector('[role=alert]'));
    check('Schedule change cannot invalidate existing appointment',(await api(`/administracion/medicos/${doctorCode}/horarios`,adminToken)).body?.[0]?.horaInicio.startsWith('08:00'));

    const second=await browser.page();await login(second,fixture.otherSecretary,'/secretaria/inicio');
    await go(second,browse,'Seleccionar turno');
    // A stale confirmation is prepared before the first session claims a fresh slot.
    const staleUrl=await second.eval(()=>document.querySelector('.r-slot').href);const staleSlot=new URL(staleUrl).searchParams.get('codSlot');
    await second.goto(staleUrl);await second.wait(()=>document.body.innerText.includes('Resumen de la reserva'));
    await secretary.goto(staleUrl);await secretary.wait(()=>document.body.innerText.includes('Resumen de la reserva'));await submit(secretary,{confirmacion:true});await secretary.wait(()=>document.body.innerText.includes('Reserva confirmada por el servicio'));
    await submit(second,{confirmacion:true});await second.wait(()=>!!document.querySelector('[role=alert]'));
    check('Stale slot rejected from another authorized browser session',await second.eval(()=>location.pathname.endsWith('/nueva')&&!location.search.includes('codSlot')));
    evidence.staleSlot=staleSlot;await capture(second,'secretaria-reserva-conflicto');

    // True simultaneous requests go directly to the real multithreaded API, because php -S is single-threaded.
    const available=(await api(`/secretaria/disponibilidad?codEsp=${fixture.codEsp}&codMed=${doctorCode}&fecha=${fixture.fecha}`,secretaryToken)).body;
    const concurrentSlot=available.at(-1).codSlot;evidence.concurrentSlot=concurrentSlot;
    const secondToken=await apiLogin(fixture.otherSecretary);
    const races=await Promise.all([api('/secretaria/citas',secretaryToken,'POST',{codPac:fixture.users.ROL001.codPac,codSlot:concurrentSlot}),api('/secretaria/citas',secondToken,'POST',{codPac:fixture.otherPatient.codPac,codSlot:concurrentSlot})]);
    check('True concurrent reservation has one 201 and one 409',races.map(r=>r.status).sort().join(',')==='201,409');
    evidence.concurrentStatuses=races.map(r=>r.status);

    await go(secretary,'/secretaria/checkin','Check-in');await capture(secretary,'secretaria-qr');
    await submit(secretary,{tokenQr:fixture.tokenQr});await secretary.wait(()=>document.body.innerText.includes('Check-in registrado'));
    const attendance=(await api('/secretaria/citas/'+fixture.codCitQr+'/asistencia',secretaryToken)).body;
    check('Actual SQL-signed QR registers arrival with actor',attendance?.estado==='En Espera'&&!!attendance.horaLlegadaCheckin&&attendance.checkinPorUsu===fixture.users.ROL002.id);
    await capture(secretary,'secretaria-checkin-exitoso');
    await go(secretary,'/secretaria/checkin','Check-in');await submit(secretary,{tokenQr:fixture.tokenQr});await secretary.wait(()=>!!document.querySelector('[role=alert]'));
    check('Duplicate real QR rejected and field cleared',await secretary.eval(()=>document.querySelector('[name=tokenQr]').value===''));
    await capture(secretary,'secretaria-qr-duplicado');

    await go(secretary,`/secretaria/citas/${appointment}/cancelar`,'Cancelar cita');await capture(secretary,'secretaria-cancelacion');
    await submit(secretary,{motivo:'Cancelacion E2E solicitada por paciente',confirmacion:true});await secretary.wait(()=>document.body.innerText.includes('La cancelación fue registrada'));
    const cancelled=(await api('/secretaria/citas/'+appointment,secretaryToken)).body;
    const freed=(await api(`/secretaria/disponibilidad?codEsp=${fixture.codEsp}&codMed=${doctorCode}&fecha=${fixture.fecha}`,secretaryToken)).body;
    check('Cancellation persisted and unblocked slot released',cancelled?.estado==='Cancelada'&&freed.some(row=>row.codSlot===selectedSlot));
    await go(secretary,`/secretaria/citas/${fixture.codCitAbsence}/inasistencia`,'Registrar inasistencia');await capture(secretary,'secretaria-inasistencia');
    await submit(secretary,{confirmacion:true});await secretary.wait(()=>document.body.innerText.includes('La inasistencia fue registrada'));
    check('Expired historical fixture records real absence',(await api('/secretaria/citas/'+fixture.codCitAbsence,secretaryToken)).body?.estado==='No Asistió');
    await capture(secretary,'secretaria-inasistencia-exitosa');

    await go(admin,`/administracion/bloqueos/medicos/${doctorCode}/crear`,'Registrar bloqueo');
    await submit(admin,{fechaBloqueo:fixture.fecha,horaInicio:'08:20',horaFin:'08:40',motivo:'Bloqueo E2E de recepcion',confirmacion:true});
    await admin.wait(()=>/^\/administracion\/bloqueos\/BLO/.test(location.pathname));
    const blockCode=await admin.eval(()=>location.pathname.split('/').at(-1));evidence.blockCode=blockCode;
    const affected=(await api('/administracion/bloqueos/'+blockCode+'/citas-afectadas',adminToken)).body;
    evidence.affected=affected;
    check('Block persists and exposes affected appointments',JSON.stringify(affected).includes('Afectada/Pendiente Reprogramación'));
    await capture(admin,'admin-bloqueo');

    const alternateSlots=(await api(`/secretaria/disponibilidad?codEsp=${specialtyCode}&codMed=${doctorCode}&fecha=${fixture.fecha}`,secretaryToken)).body;
    await go(admin,`/administracion/especialidades/${specialtyCode}`,'Especialidad');
    await submit(admin,{confirmacion:true},'form[action$="/estado"]');await admin.wait(()=>document.querySelector('input[name=estado]')?.value==='Activo');
    check('Inactive specialty excluded from reception catalog',!(await api('/secretaria/especialidades',secretaryToken)).body.some(row=>row.codEsp===specialtyCode));
    check('Inactive specialty has no reservable availability',(await api(`/secretaria/disponibilidad?codEsp=${specialtyCode}&codMed=${doctorCode}&fecha=${fixture.fecha}`,secretaryToken)).body.length===0);
    check('Inactive specialty cannot be booked with a stale slot',(await api('/secretaria/citas',secretaryToken,'POST',{codPac:fixture.users.ROL001.codPac,codSlot:alternateSlots[0].codSlot})).status===409);
    await submit(admin,{confirmacion:true},'form[action$="/estado"]');await admin.wait(()=>document.querySelector('input[name=estado]')?.value==='Inactivo');
    check('Specialty reactivated',(await api('/personal/especialidades/'+specialtyCode,adminToken)).body?.estado==='Activo');
    check('Specialty linked-doctor endpoint reflects doctor',JSON.stringify((await api('/personal/especialidades/'+specialtyCode+'/medicos',adminToken)).body).includes(doctorCode));

    await go(admin,`/administracion/pacientes/${fixture.sanctionPatient.codPac}`,fixture.sanctionPatient.name);await capture(admin,'admin-paciente');
    await go(admin,`/administracion/pacientes/${fixture.sanctionPatient.codPac}/sanciones/crear`,'Aplicar sanción');
    await submit(admin,{motivo:'Sancion temporal E2E auditable',dias:'7',confirmacion:true});
    await waitPath(admin,`/administracion/pacientes/${fixture.sanctionPatient.codPac}`);
    const sanctions=(await api('/administracion/pacientes/'+fixture.sanctionPatient.codPac+'/sanciones',adminToken)).body;
    const sanctionCode=sanctions.incidencias[0].codInc;evidence.sanctionCode=sanctionCode;
    await go(admin,`/administracion/pacientes/${fixture.sanctionPatient.codPac}/sanciones/${sanctionCode}`);await capture(admin,'admin-sancion');
    const restricted=(await api('/secretaria/pacientes/'+fixture.sanctionPatient.codPac,secretaryToken)).body;
    check('Real sanction changes effective eligibility',restricted?.habilitadoParaReservar===false);
    const restrictionAttempt=await api('/secretaria/citas',secretaryToken,'POST',{codPac:fixture.sanctionPatient.codPac,codSlot:selectedSlot});
    check('Sanction prevents new reservation',restrictionAttempt.status===403);
    check('Sanctioned patient receives 423 on login',(await api('/auth/login',null,'POST',{email:fixture.sanctionPatient.email,password:fixture.sanctionPatient.password})).status===423);
    await go(secretary,`/secretaria/pacientes/${fixture.sanctionPatient.codPac}`,fixture.sanctionPatient.name);await capture(secretary,'secretaria-paciente-restringido');

    await go(secretary,`/secretaria/pacientes/${fixture.historyPatient.codPac}/historial`,'Historial administrativo');
    check('History pagination has next page',await secretary.eval(()=>[...document.querySelectorAll('a')].some(a=>a.href.includes('page=1'))));
    await capture(secretary,'secretaria-historial');
    await go(secretary,`/secretaria/pacientes/${fixture.historyPatient.codPac}/historial?page=1&size=20&estado=Cancelada&desde=${fixture.today}&hasta=${fixture.todayPlus14}`,'Historial administrativo');
    check('History second page and filters reach real API',(await secretary.text()).includes('Cancelada')&&await secretary.eval(()=>document.querySelectorAll('tbody tr').length===3));
    await go(secretary,`/secretaria/citas?fecha=${fixture.fecha}&estado=Cancelada&page=0&size=1`,'Gestión de citas');await capture(secretary,'secretaria-citas');
    check('Real appointment list filtered and paginated',await secretary.eval(()=>document.querySelectorAll('tbody tr').length===1));
    check('D+15 rejected by real availability',(await api(`/secretaria/disponibilidad?codEsp=${fixture.codEsp}&fecha=${fixture.todayPlus15}`,secretaryToken)).status===400);
    check('D+14 accepted by real availability',(await api(`/secretaria/disponibilidad?codEsp=${fixture.codEsp}&fecha=${fixture.todayPlus14}`,secretaryToken)).status===200);
    await go(secretary,'/secretaria/citas/CIT99999999');check('Real missing resource renders recoverable 404',secretary.documents.at(-1)?.status===404);await capture(secretary,'secretaria-no-encontrada');
    await go(secretary,'/secretaria/inicio','Panel de Secretaría');
    await secretary.goto('http://127.0.0.1:18092/secretaria/inicio');
    check('Actual transport outage blocks protected operation',secretary.documents.at(-1)?.status===503);await capture(secretary,'secretaria-servicio-caido');
    await go(secretary,'/secretaria/inicio','Panel de Secretaría');check('Same session recovers when backend is reachable',secretary.documents.at(-1)?.status===200);
    await secretary.viewport(390,844);await secretary.eval(()=>document.querySelector('[data-open-admin-menu]').click());
    check('Mobile navigation opens',await secretary.eval(()=>document.querySelector('#admin-mobile-menu').open));
    await secretary.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await secretary.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    check('Escape closes mobile navigation',await secretary.eval(()=>!document.querySelector('#admin-mobile-menu').open));
    check('No browser JavaScript exceptions',Object.values(roles).every(page=>page.exceptions===0)&&second.exceptions===0);
} catch(error) {
    results.push({label:'Execution stopped: '+error.message,status:'FALLIDO'});
    if(currentPage)try{await currentPage.screenshot(resolve(output,'screens','failure.jpg'));writeFileSync(resolve(output,'failure.html'),await currentPage.eval(()=>document.documentElement.outerHTML));}catch{}
} finally {
    writeFileSync(resolve(output,'browser-e2e.json'),JSON.stringify({source:'Chrome -> Laravel HTTP/cookies/CSRF -> Quarkus -> exclusive PostgreSQL 15',database:fixture.database,results,evidence,snapshots},null,2));
    if(browser)await browser.close();
}
console.log(JSON.stringify({checks:results.length,passed:results.filter(r=>r.status==='APROBADO CON BACKEND REAL').length,failed:results.filter(r=>r.status==='FALLIDO').length}));
process.exitCode=results.some(r=>r.status==='FALLIDO')?1:0;
