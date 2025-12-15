{**
 * Enlace en "Mi cuenta" del cliente para ver sus facturas
 *}

<a class="col-lg-4 col-md-6 col-sm-6 col-xs-12" id="facturas-facturascripts-link" href="{$link->getModuleLink('fsfacturascripts', 'myinvoices', [], true)|escape:'html':'UTF-8'}" title="{l s='Mis Facturas' mod='fsfacturascripts'}">
    <span class="link-item">
        <i class="material-icons">&#xE873;</i>
        {l s='Mis Facturas FacturaScripts' mod='fsfacturascripts'}
    </span>
</a>
