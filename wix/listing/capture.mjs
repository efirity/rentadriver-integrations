import {spawn} from 'node:child_process';
import {readFile,writeFile,mkdtemp} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {resolve} from 'node:path';
const dir=fileURLToPath(new URL('./',import.meta.url));
// Brand assets (mark-reversed.svg, fonts/Sora-SemiBold.ttf) are read from BRAND_DIR; default: this folder.
const brandDir=process.env.BRAND_DIR?resolve(process.env.BRAND_DIR)+'/':dir;
const profile=await mkdtemp('/private/tmp/rd-wix-media-chrome-');
const chrome=spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',['--headless=new','--disable-gpu','--hide-scrollbars','--disable-background-networking','--no-first-run','--remote-debugging-port=9491',`--user-data-dir=${profile}`,'about:blank'],{stdio:'ignore'});
let ws;
try{
 for(let i=0;i<60;i++){try{if((await fetch('http://127.0.0.1:9491/json/version')).ok)break;}catch{}await new Promise(r=>setTimeout(r,250));}
 const tab=(await(await fetch('http://127.0.0.1:9491/json')).json()).find(t=>t.type==='page');
 ws=new WebSocket(tab.webSocketDebuggerUrl);await new Promise(r=>ws.addEventListener('open',r,{once:true}));
 let seq=0;const pending=new Map();ws.addEventListener('message',e=>{let m=JSON.parse(e.data);if(m.id){let p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}});
 const cdp=(method,params={})=>new Promise((resolve,reject)=>{let id=++seq;pending.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params}));});
 const ev=async expression=>{let r=await cdp('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error('Browser expression failed');return r.result.value;};
 const wait=async e=>{for(let i=0;i<100;i++){if(await ev(`Boolean(${e})`))return;await new Promise(r=>setTimeout(r,250));}throw Error('Wait failed: '+e+'; '+await ev('document.body.innerText.slice(0,2000)'));};
 const size=async(width,height)=>cdp('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
 const shot=async name=>{await ev('document.fonts.ready.then(()=>true)');let r=await cdp('Page.captureScreenshot',{format:'png'});await writeFile(dir+name,Buffer.from(r.data,'base64'));};
 const click=async t=>{await ev(`(()=>{const b=[...document.querySelectorAll('button')].find(b=>b.textContent.trim()===${JSON.stringify(t)});if(!b)throw Error('Missing tab');b.click();window.scrollTo(0,0)})()`);await new Promise(r=>setTimeout(r,700));};
 await cdp('Page.enable');await cdp('Runtime.enable');await cdp('Network.enable');await cdp('Network.setBlockedURLs',{urls:['https://*']});
 await size(1440,850);await cdp('Page.navigate',{url:'http://localhost:8931/local/wix'});
 await wait(`document.body?.innerText.includes('From checkout to doorstep.') && document.body.innerText.toLowerCase().includes('northampton flowers')`);
 await ev(`(()=>{const s=document.createElement('style');s.textContent='nextjs-portal{display:none!important}';document.head.append(s)})()`);
 await shot('screenshots/overview.png');
 await click('Orders');await wait(`document.body.innerText.includes('#1001')`);await shot('screenshots/orders.png');
 await size(440,960);await ev('window.scrollTo(0,140)');await new Promise(r=>setTimeout(r,300));await shot('screenshots/mobile.png');
 await size(1440,850);await click('Settings');
 await ev(`(()=>{let f=document.querySelector('form[aria-label="Delivery settings"]');window.scrollTo(0,f.getBoundingClientRect().top+scrollY-24)})()`);
 await new Promise(r=>setTimeout(r,300));await shot('screenshots/settings.png');
 await ev(`(()=>{let s=[...document.querySelectorAll('section')].find(s=>s.innerText.startsWith('BOOKING & PICKUP'));if(!s) s=[...document.querySelectorAll('section')].find(s=>s.innerText.toLowerCase().startsWith('booking & pickup'));if(!s)throw Error('Pickup card missing');window.scrollTo(0,s.getBoundingClientRect().top+scrollY-24)})()`);
 await new Promise(r=>setTimeout(r,300));await shot('screenshots/pickup.png');
 console.log('Captured five real Wix app views with local sample data.');
 const logo=(await readFile(brandDir+'mark-reversed.svg')).toString('base64');
 const font=(await readFile(brandDir+'fonts/Sora-SemiBold.ttf')).toString('base64');
 const cards=[
 ['01-main','Local delivery.\nRight from your store.','Offer nearby customers a delivery option at checkout.','overview'],
 ['02-orders','Every order. One clear view.','Book deliveries and follow their progress from your dashboard.','orders'],
 ['03-delivery-settings','Delivery, on your terms.','Choose your radius, preparation time and customer pricing.','settings'],
 ['04-pickup','Set up a smoother pickup.','Choose booking rules, pickup details and proof of delivery.','pickup'],
 ['05-mobile','Your store.\nWithin reach.','Check orders and delivery status\nfrom a smaller screen.','mobile']
 ];
 let pages=[];
 for(const [name,title,sub,screen] of cards){
  let src=(await readFile(dir+'screenshots/'+screen+'.png')).toString('base64');
  let mobile=screen==='mobile',main=screen==='overview';
  let html=`<!doctype html><meta charset="utf-8"><title>${title.replace('\n',' ')}</title><style>@font-face{font-family:Sora;src:url(data:font/ttf;base64,${font})}*{box-sizing:border-box}html,body{margin:0;width:1600px;height:1200px;overflow:hidden}body{background:#2855D9;color:white;font-family:Arial,sans-serif}.brand{position:absolute;left:80px;top:45px;display:flex;gap:17px;align-items:center;font-family:Sora;font-size:28px}.brand img{width:54px;height:54px}.platform{border-left:1px solid #ffffff65;margin-left:8px;padding-left:24px;font:18px Arial;letter-spacing:2px;text-transform:uppercase}.headline{position:absolute;left:80px;top:${main?'124':'126'}px;right:60px;margin:0;font-family:Sora;font-size:${main?58:54}px;line-height:1.16;letter-spacing:-2px;white-space:pre-line}.sub{position:absolute;left:82px;top:${main?'268':'202'}px;font-size:25px;line-height:1.5;margin:0;color:#fff;white-space:pre-line}.window{position:absolute;left:80px;top:${main?'337':'273'}px;width:1440px;height:${main?'790':'850'}px;border-radius:22px;background:#f5f7fc;box-shadow:0 22px 45px #102a7645;overflow:hidden;border:1px solid #ffffff80}.window img{width:1440px;height:850px;object-fit:cover;object-position:top}.footer{position:absolute;left:82px;bottom:26px;right:82px;display:flex;justify-content:space-between;align-items:center;font-size:17px;letter-spacing:.2px;color:#e4ebff}.mobile .headline{top:320px;width:730px;font-size:76px;line-height:1.12}.mobile .sub{top:542px;font-size:30px;line-height:1.6}.mobile .window{left:1000px;top:146px;width:466px;height:990px;border-radius:38px;padding:12px;border:1px solid #ffffff80;background:#14223b}.mobile .window img{width:440px;height:960px;border-radius:26px}.mobile .tag{position:absolute;left:82px;top:750px;border-top:1px solid #ffffff55;width:660px;padding-top:30px;font-size:24px;line-height:1.6;color:#e4ebff}</style><body class="${mobile?'mobile':''}"><div class="brand"><img src="data:image/svg+xml;base64,${logo}">RentADriver<span class="platform">For Wix Stores</span></div><h1 class="headline">${title}</h1><p class="sub">${sub}</p><div class="window"><img src="data:image/png;base64,${src}"></div>${mobile?'<div class="tag">Order management<br>Delivery status<br>Responsive dashboard</div>':''}<div class="footer"><span>App preview · Sample store and orders</span><span>${cards.findIndex(c=>c[0]===name)+1} / 5</span></div></body>`;
  await writeFile(dir+name+'.html',html);await size(1600,1200);await cdp('Page.navigate',{url:'file://'+dir+name+'.html'});await wait('document.images.length===2 && [...document.images].every(i=>i.complete)');await shot(name+'.png');pages.push({name,title:title.replace('\n',' ')});console.log('Exported '+name+'.png (1600 × 1200)');
 }
 await writeFile(dir+'index.html',`<!doctype html><meta charset="utf-8"><title>RentADriver Wix listing images</title><style>body{margin:0;padding:35px;background:#eef2fa;font:20px Arial;color:#14223b}h1{font-size:28px}main{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}img{width:100%;border-radius:12px}a{color:inherit;text-decoration:none}p{font-size:16px}</style><h1>RentADriver for Wix Stores · Listing media</h1><p>Five PNG images · 1600 × 1200 · Actual app screens with sample data</p><main>${pages.map(p=>`<a href="${p.name}.png"><img src="${p.name}.png"><p>${p.title}</p></a>`).join('')}</main>`);
 await size(1600,1050);await cdp('Page.navigate',{url:'file://'+dir+'index.html'});await wait('[...document.images].every(i=>i.complete)');await shot('contact-sheet.png');
}finally{ws?.close();chrome.kill('SIGTERM');}
