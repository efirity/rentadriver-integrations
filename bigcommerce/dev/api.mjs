// In-memory UI fixtures. No BigCommerce OAuth, provider calls, database, or dispatch.
import {createServer} from 'node:http';
export const DEMO_TOKEN = 'local-bigcommerce-demo';
export function createDemoServer({ webPort = 3005, apiPort = 8790 } = {}) {
const web=`http://127.0.0.1:${webPort}`, base=`http://127.0.0.1:${apiPort}`, token=DEMO_TOKEN;
const now=new Date().toISOString();
let settings={enabled:true,radius_km:8,max_size_class:'M',cutoff_time:'16:00',next_day_after_cutoff:true,prep_minutes:20,auto_book:'rate_only',pricing:{mode:'flat',flat_cents:590,adjust_pct:0,free_over_cents:null},rate_label:'Same-day by RentADriver',rate_description:'Local demo delivery',proof_required:['photo','recipient_name'],notify_customer:false,fulfill_on:'picked_up',driver_instructions:'Demo: collect at the counter',pickup_contact_name:'Demo shop',pickup_contact_phone:'',min_subtotal_cents:0,offer_next_day:false,next_day_hour:9,next_day_label:'Tomorrow morning',merchant_alerts:false,pickup_address:'12 Example Street, London',complete_order_on_delivered:true};
const store={id:'local-demo-store',key:'local-demo-site',url:'bigcommerce-demo.example.test',name:'BigCommerce local demo',currency:'GBP',timezone:'Europe/London',plan:'sandbox',location:{address:settings.pickup_address,lat:51.5072,lng:-0.1276,area_slug:'demo-london',name:'Demo shop'},last_error:null,rate_requests:0,orders_booked:2,installed_at:now,callback_token:'local-fixture-only'};
const delivery=(code,status='assigned')=>({id:code,short_code:code,status,price_cents:790,currency:'GBP',tracking_url:`${base}/demo/tracking/${code}`});
let orders=[{id:'demo-1001',order_number:'#1001',status:'booked',customer_name:'Alex (demo)',rate_code:'RD_SAMEDAY',customer_paid_cents:590,currency:'GBP',last_error:null,created_at:now,delivery:delivery('DEMO1001')},{id:'demo-1002',order_number:'#1002',status:'booked',customer_name:'Sam (demo)',rate_code:'RD_SAMEDAY',customer_paid_cents:590,currency:'GBP',last_error:null,created_at:now,delivery:delivery('DEMO1002','delivered')}];
const remote=[{order_id:'1003',order_number:'#1003',created_at:now,paid:true,status:'pending',total_cents:4590,currency:'GBP',city:'London',rate:'Same-day by RentADriver'},{order_id:'1004',order_number:'#1004',created_at:now,paid:false,status:'pending',total_cents:2500,currency:'GBP',city:'London',rate:null}];
const overview=()=>({success:true,platform:'bigcommerce',sandbox:true,features:{live_rates:'native',remote_orders:true,pickup_address_required:true,embedded:true,store_key_label:'Store hash'},store,settings,workspaces:[{id:'demo-gb',country_code:'GB',currency:'GBP',wallet_balance_cents:10000}],account:{id:'demo-account',workspace_id:'demo-gb',name:'Local demo account — sample balance',wallet_balance_cents:10000,currency:'GBP',managed:true},coverage:{covered:true,area:{slug:'demo-london',name:'London (demo)',currency:'GBP',radius_km:8}},week:{rate_requests:0,rate_requests_covered:0,orders_booked:orders.filter(o=>o.status==='booked').length},urls:{app:`${web}/bigcommerce`,rates_callback:'',webhooks:`${base}/v1/bigcommerce/webhooks`,console:`${base}/demo`}});
return createServer(async(req,res)=>{
 const origin=req.headers.origin;
 if(origin && ![web,`http://localhost:${webPort}`].includes(origin)){res.writeHead(403);res.end();return;}
 res.setHeader('Access-Control-Allow-Origin',origin||web);res.setHeader('Vary','Origin');res.setHeader('Access-Control-Allow-Headers','Content-Type, Authorization');res.setHeader('Access-Control-Allow-Methods','GET, POST, PATCH, OPTIONS');res.setHeader('Cache-Control','no-store');
 const send=(value,status=200)=>{res.writeHead(status,{'Content-Type':'application/json'});res.end(JSON.stringify(value));};
 const fail=(message,status=400)=>send({success:false,error:{code:'local_demo',message}},status);
 if(req.method==='OPTIONS'){res.writeHead(204);res.end();return;}
 const u=new URL(req.url,base), path=u.pathname;
 if(path==='/healthz'){send({status:'ok',mode:'in-memory-demo',external_services:false});return;}
 if(path==='/v1/bigcommerce/install'){res.writeHead(302,{Location:`${web}/bigcommerce#handoff=local-demo-entry`});res.end();return;}
 if(path.startsWith('/demo')){res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});res.end(`<html><body style="font:18px system-ui;max-width:700px;margin:80px auto"><h1>BigCommerce local demo</h1><p>Sample data only. Booking changes are held in memory; payments, notifications, tracking and live dispatch are not connected.</p><a href="${web}/bigcommerce#handoff=local-demo-entry">Return to the merchant app</a></body></html>`);return;}
 let body={};try{let raw='';for await(const part of req){raw+=part;if(raw.length>20000)throw Error();}body=raw?JSON.parse(raw):{};}catch{fail('Invalid demo request');return;}
 if(path==='/v1/bigcommerce/session/exchange'){if(body.handoff!=='local-demo-entry'){fail('Invalid local demo handoff',401);return;}send({token});return;}
 if(path==='/v1/bigcommerce/session/by-key'){if(body.api_key!==token){fail(`Use the demo key: ${token}`,401);return;}send({...overview(),token});return;}
 if(req.headers.authorization!==`Bearer ${token}`){fail('Open the local demo link to sign in',401);return;}
 if(path==='/v1/bigcommerce/app/console-link'&&req.method==='POST'){send({success:true,url:`${base}/demo`});return;}
 if(path==='/v1/bigcommerce/app/me'||path==='/v1/bigcommerce/app/sync'){send(overview());return;}
 if(path==='/v1/bigcommerce/app/settings'&&req.method==='PATCH'){settings={...settings,...body,pricing:{...settings.pricing,...body.pricing}};store.location.address=settings.pickup_address;send({success:true,settings,store});return;}
 if(path==='/v1/bigcommerce/app/test-rate' && req.method==='POST'){send({success:true,options:[{title:'Sample same-day rate',amount_cents:590,currency:'GBP',description:'Fixed demo quote; address eligibility and live pricing are not evaluated.'}],quote_cents:790,quote_currency:'GBP',distance_m:2400,size_class:'M',same_day:true});return;}
 if(path==='/v1/bigcommerce/app/orders'){send({success:true,orders:u.searchParams.get('source')==='remote'?remote.map(r=>({...r,local:orders.find(o=>o.id===r.order_id)??null})):orders});return;}
 const action=path.match(/^\/v1\/bigcommerce\/app\/orders\/([^/]+)\/(book|cancel|return)$/);
 if(action&&req.method==='POST'){
  const ref=decodeURIComponent(action[1]);let local=orders.find(o=>o.id===ref||o.order_number.replace('#','')===ref.replace('#',''));
  const external=remote.find(o=>o.order_id===ref||o.order_number.replace('#','')===ref.replace('#',''));
  if(action[2]==='book'){
   if(local?.status==='booked'){send({success:true,order:local,delivery:local.delivery});return;}
   if(!local&&!external){fail('Demo orders: 1001, 1002, 1003 and 1004',404);return;}
   if(external&&!external.paid){fail('This demo order is not paid');return;}
   if(!local){local={id:external.order_id,order_number:external.order_number,status:'pending',customer_name:'Taylor (demo)',rate_code:'RD_SAMEDAY',customer_paid_cents:590,currency:'GBP',last_error:null,created_at:now,delivery:null};orders.push(local);}
   local.status='booked';local.delivery=delivery('DEMO'+local.order_number.slice(1));send({success:true,order:local,delivery:local.delivery});return;
  }
  if(!local){fail('Demo order not found',404);return;}
  if(action[2]==='cancel'){local.status='cancelled';local.delivery.status='cancelled';send({success:true,order:local});return;}
  local.return_delivery=delivery('RETURN'+local.order_number.slice(1));send({success:true,order:local,delivery:local.return_delivery});return;
 }
 if(path.endsWith('/wallet/deposit')){fail('Local demo: payments are disabled; the balance is sample data.',409);return;}
 if(path.endsWith('/app/connect')){fail('Local demo: connecting real accounts is disabled.',409);return;}
 fail('This endpoint is not part of the local UI demo.',404);
});
}
