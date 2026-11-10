<section class="zibalpayment-return">
  {if $zibal_is_paid}
    <p class="alert alert-success">{l s='پرداخت شما با موفقیت انجام شد.' mod='zibalpayment'}</p>
  {else}
    <p class="alert alert-warning">{l s='سفارش ثبت شد اما وضعیت پرداخت آن نیاز به بررسی دارد. لطفاً با پشتیبانی فروشگاه تماس بگیرید.' mod='zibalpayment'}</p>
  {/if}
  <ul>
    <li>{l s='شماره سفارش:' mod='zibalpayment'} <strong>{$zibal_reference|escape:'html':'UTF-8'}</strong></li>
  </ul>
</section>
