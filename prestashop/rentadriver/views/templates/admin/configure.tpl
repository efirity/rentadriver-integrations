{**
 * RentADriver module settings. @license GPL-2.0-or-later
 *}
<div class="panel">
  <h3><i class="icon-truck"></i> {l s='RentADriver Same-Day Delivery' mod='rentadriver'} <small>v{$rd_version|escape:'html':'UTF-8'}</small></h3>
  {if $rd_connected}
    <p>{l s='Connected as' mod='rentadriver'} <strong>{$rd_store|escape:'html':'UTF-8'}</strong>.
      {if $rd_carrier}{l s='Carrier:' mod='rentadriver'} <strong>{$rd_carrier|escape:'html':'UTF-8'}</strong>.{/if}</p>
    <p>{l s='Prices, delivery area, automatic booking and your delivery account are managed in RentADriver.' mod='rentadriver'}</p>
    <p>
      <a class="btn btn-primary" href="{$rd_open|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{l s='Open RentADriver' mod='rentadriver'}</a>
      <a class="btn btn-default" href="{$rd_setup|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{l s='Guided setup' mod='rentadriver'}</a>
    </p>
  {else}
    <p>{l s='Connect your shop to offer same-day local delivery by vetted RentADriver drivers. You will sign in to RentADriver (or create an account) and choose your prices and delivery area there.' mod='rentadriver'}</p>
    <form method="post" action="{$rd_action|escape:'html':'UTF-8'}">
      <button type="submit" name="rd_connect" value="1" class="btn btn-primary">{l s='Connect to RentADriver' mod='rentadriver'}</button>
    </form>
  {/if}
</div>
<form method="post" action="{$rd_action|escape:'html':'UTF-8'}" class="defaultForm form-horizontal">
  <div class="panel">
    <h3>{l s='Order statuses' mod='rentadriver'}</h3>
    <div class="form-group">
      <label class="control-label col-lg-3">{l s='When the driver collects the parcel' mod='rentadriver'}</label>
      <div class="col-lg-6"><select name="rd_status_shipped">{foreach $rd_states as $s}<option value="{$s.id_order_state|intval}"{if $s.id_order_state == $rd_status_shipped} selected{/if}>{$s.name|escape:'html':'UTF-8'}</option>{/foreach}</select></div>
    </div>
    <div class="form-group">
      <label class="control-label col-lg-3">{l s='When the parcel is delivered' mod='rentadriver'}</label>
      <div class="col-lg-6"><select name="rd_status_delivered">{foreach $rd_states as $s}<option value="{$s.id_order_state|intval}"{if $s.id_order_state == $rd_status_delivered} selected{/if}>{$s.name|escape:'html':'UTF-8'}</option>{/foreach}</select>
        <p class="help-block">{l s='Used only when "Complete the order when delivered" is on in RentADriver.' mod='rentadriver'}</p></div>
    </div>
    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Test orders' mod='rentadriver'}</label>
      <div class="col-lg-6"><label><input type="checkbox" name="rd_mark_test_orders" value="1"{if $rd_mark_test_orders} checked{/if}> {l s='Mark new orders as test orders' mod='rentadriver'}</label>
        <p class="help-block">{l s='For trying RentADriver in sandbox mode: only test orders receive sandbox delivery updates. Turn it off before taking real orders.' mod='rentadriver'}</p></div>
    </div>
    <div class="panel-footer"><button type="submit" name="rd_save" value="1" class="btn btn-default pull-right"><i class="process-icon-save"></i> {l s='Save' mod='rentadriver'}</button></div>
  </div>
</form>
{if $rd_connected}
<form method="post" action="{$rd_action|escape:'html':'UTF-8'}"><button type="submit" name="rd_disconnect" value="1" class="btn btn-link">{l s='Disconnect this shop from RentADriver' mod='rentadriver'}</button></form>
{/if}
