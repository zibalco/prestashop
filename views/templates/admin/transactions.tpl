<div class="panel">
  <div class="panel-heading">
    <i class="icon-warning-sign"></i> {l s='تراکنش‌های نیازمند بررسی' mod='zibalpayment'}
  </div>
  <p class="help-block">
    {l s='تراکنش‌هایی که ممکن است پول آن‌ها کسر شده ولی سفارش ثبت نشده (verified / inquiry)، سفارش‌هایی با مبلغ ناهمخوان (mismatch) و تراکنش‌های در انتظاری که خطا داشته‌اند. با «استعلام مجدد» پاسخ فعلی زیبال نمایش داده می‌شود.' mod='zibalpayment'}
  </p>
  {if $zibal_transactions}
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>#</th>
            <th>{l s='تاریخ' mod='zibalpayment'}</th>
            <th>{l s='سبد' mod='zibalpayment'}</th>
            <th>{l s='سفارش' mod='zibalpayment'}</th>
            <th>trackId</th>
            <th>{l s='مبلغ (ریال)' mod='zibalpayment'}</th>
            <th>{l s='وضعیت' mod='zibalpayment'}</th>
            <th>{l s='آخرین خطا' mod='zibalpayment'}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$zibal_transactions item=tx}
            <tr>
              <td>{$tx.id_zibal_transaction|intval}</td>
              <td>{$tx.date_add|escape:'html':'UTF-8'}</td>
              <td>{$tx.id_cart|intval}</td>
              <td>{if $tx.id_order}{$tx.id_order|intval}{else}-{/if}</td>
              <td>{$tx.track_id|escape:'html':'UTF-8'}</td>
              <td>{$tx.amount|escape:'html':'UTF-8'}</td>
              <td><span class="badge">{$tx.status|escape:'html':'UTF-8'}</span></td>
              <td><small>{$tx.last_error|escape:'html':'UTF-8'}</small></td>
              <td>
                <a class="btn btn-default btn-sm" href="{$tx.inquiry_url|escape:'html':'UTF-8'}">
                  {l s='استعلام مجدد' mod='zibalpayment'}
                </a>
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  {else}
    <p>{l s='موردی برای بررسی وجود ندارد.' mod='zibalpayment'}</p>
  {/if}
</div>
