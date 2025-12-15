{extends file='customer/page.tpl'}

{block name='page_title'}
    {l s='My FacturaScripts Invoices' mod='fsfacturascripts'}
{/block}

{block name='page_content'}
    <section id="content" class="page-content">
        <h1 class="h1">{l s='My Invoices' mod='fsfacturascripts'}</h1>

        {if $invoices && count($invoices) > 0}
            <table class="table table-striped table-bordered table-labeled">
                <thead class="thead-default">
                    <tr>
                        <th>{l s='Order Reference' mod='fsfacturascripts'}</th>
                        <th>{l s='Date' mod='fsfacturascripts'}</th>
                        <th>{l s='Total' mod='fsfacturascripts'}</th>
                        <th>{l s='Invoice Code' mod='fsfacturascripts'}</th>
                        <th>{l s='Actions' mod='fsfacturascripts'}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$invoices item=invoice}
                        <tr>
                            <td>{$invoice.order_reference|escape:'html':'UTF-8'}</td>
                            <td>{$invoice.date_add|date_format:"%d/%m/%Y"}</td>
                            <td>{$invoice.total_paid|string_format:"%.2f"} €</td>
                            <td><strong>{$invoice.fs_factura_code|escape:'html':'UTF-8'}</strong></td>
                            <td>
                                <a href="{$invoice.download_url|escape:'html':'UTF-8'}" target="_blank" class="btn btn-primary btn-sm">
                                    <i class="material-icons">&#xE884;</i>
                                    {l s='Download PDF' mod='fsfacturascripts'}
                                </a>
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {else}
            <div class="alert alert-warning">
                {l s='You don\'t have any invoices from FacturaScripts yet.' mod='fsfacturascripts'}
            </div>
        {/if}

        <a href="{$urls.pages.my_account}" class="btn btn-secondary">
            <i class="material-icons">&#xE5C4;</i>
            {l s='Back to My Account' mod='fsfacturascripts'}
        </a>
    </section>
{/block}
