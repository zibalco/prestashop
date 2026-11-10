{extends file='page.tpl'}

{block name='page_title'}
  {l s='وضعیت پرداخت' mod='zibalpayment'}
{/block}

{block name='page_content'}
  <div class="alert alert-danger" role="alert">
    {$zibal_error|escape:'html':'UTF-8'}
  </div>

  <p>
    <a class="btn btn-primary" href="{$zibal_retry_url|escape:'html':'UTF-8'}">{l s='بازگشت به صفحه پرداخت' mod='zibalpayment'}</a>
  </p>
{/block}
