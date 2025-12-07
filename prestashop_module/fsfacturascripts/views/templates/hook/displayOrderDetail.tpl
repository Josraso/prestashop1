{**
 * Botón de descarga de factura en detalle del pedido (front)
 *}

<div class="box">
    <div class="row">
        <div class="col-md-12">
            <h3>{l s='Factura FacturaScripts' mod='fsfacturascripts'}</h3>
            <p>
                <strong>{l s='Código de factura:' mod='fsfacturascripts'}</strong> {$fs_factura_code|escape:'html':'UTF-8'}
            </p>
            <a href="{$fs_download_url|escape:'html':'UTF-8'}" target="_blank" class="btn btn-primary">
                <i class="icon-download"></i>
                {l s='Descargar Factura PDF' mod='fsfacturascripts'}
            </a>
        </div>
    </div>
</div>
