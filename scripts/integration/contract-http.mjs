// Real HTTP boundary checks. No Http::fake or fault proxy is used here.
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
if(process.env.RUN_CLINICA_E2E!=='1')throw new Error('Explicit opt-in required.');
const output=resolve('backend_clinica/target/integration-fase12');
const fixture=JSON.parse(readFileSync(resolve(output,'browser-fixture.json'),'utf8'));
const clinical=JSON.parse(readFileSync(resolve(output,'browser-e2e.json'),'utf8'));
if(fixture.database!==clinical.database||!/^clinica_fase12_e2e_[a-f0-9]{12}$/.test(fixture.database))throw new Error('Owned browser dataset required.');
const checks=[],cookies=new Map();
const check=(label,value)=>{checks.push({label,status:value?'APROBADO CON BACKEND REAL':'FALLIDO'});if(!value)throw new Error(label);};
const http=async(path,options={})=>{
    const response=await fetch(fixture.laravelUrl+path,{...options,redirect:'manual',headers:{...options.headers,Cookie:[...cookies].map(([key,value])=>key+'='+value).join('; ')}});
    for(const header of response.headers.getSetCookie()){const pair=header.split(';')[0];const at=pair.indexOf('=');cookies.set(pair.slice(0,at),pair.slice(at+1));}
    return {status:response.status,location:response.headers.get('location'),html:await response.text()};
};
const csrf=html=>html.match(/name="_token" value="([^"]+)"/)[1];
try{
    const login=await http('/login');
    const authenticated=await http('/login',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_token:csrf(login.html),email:fixture.observerAdmin.email,password:fixture.observerAdmin.password})});
    check('Boundary test authenticates through normal Laravel login',authenticated.status===302&&authenticated.location.endsWith('/administracion/inicio'));
    const path=`/administracion/bloqueos/medicos/${clinical.evidence.doctorCode}/crear`;
    const form=await http(path);check('Real Blade form advertises maximum 500',form.status===200&&form.html.includes('maxlength="500"'));
    const reason='á'.repeat(501);
    const rejected=await http(`/administracion/bloqueos/medicos/${clinical.evidence.doctorCode}`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Referer:fixture.laravelUrl+path},body:new URLSearchParams({_token:csrf(form.html),fechaBloqueo:fixture.fecha,motivo:reason,confirmacion:'1'})});
    const restored=await http(path);
    check('Laravel rejects 501 characters next to the field and preserves input',rejected.status===302&&restored.html.includes('id="motivo-error"')&&restored.html.includes(reason));
    const apiLogin=await fetch(fixture.apiUrl+'/auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:fixture.observerAdmin.email,password:fixture.observerAdmin.password})});
    const session=await apiLogin.json();const token=session.data.session.accessToken;
    const invalidBlock=await fetch(fixture.apiUrl+`/administracion/medicos/${clinical.evidence.doctorCode}/bloqueos`,{method:'POST',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json'},body:JSON.stringify({fechaBloqueo:fixture.fecha,motivo:reason})});
    check('Quarkus independently rejects 501-character block reason with 400',invalidBlock.status===400);
    const invalidLogin=await fetch(fixture.apiUrl+'/auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});check('Real validation returns 422 for missing credentials',invalidLogin.status===422);
    const stateRead=await fetch(fixture.apiUrl+`/personal/medicos/${clinical.evidence.doctorCode}/estado`,{headers:{Authorization:'Bearer '+token}});check('Administrative state cannot be written through GET',stateRead.status===405);
    const doctor=await(await fetch(fixture.apiUrl+`/personal/medicos/${clinical.evidence.doctorCode}`,{headers:{Authorization:'Bearer '+token}})).json();
    check('Doctor remains active and response excludes credential material',doctor.estado==='Activo'&&!['password','passwordHash','password_hash','tokenQr','accessToken'].some(key=>Object.hasOwn(doctor,key)));
}catch(error){checks.push({label:error.message,status:'FALLIDO'});}
finally{writeFileSync(resolve(output,'contract-http.json'),JSON.stringify({source:'Real Laravel HTTP/cookies/CSRF and real Quarkus/PostgreSQL',checks},null,2));}
console.log(JSON.stringify({checks:checks.length,passed:checks.filter(c=>c.status!=='FALLIDO').length,failed:checks.filter(c=>c.status==='FALLIDO').length}));process.exitCode=checks.some(c=>c.status==='FALLIDO')?1:0;
