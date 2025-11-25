<?php

namespace FacturaScripts\Plugins\Prestashop;

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Plugins\Prestashop\Lib\Actions\InvoiceDownload;
use FacturaScripts\Plugins\Prestashop\Lib\Actions\OrdersDownload;

class Cron extends CronClass
{
    /**
     * @throws \Exception
     */
    public function run(): void
    {
        (new InvoiceDownload())->batch();
        (new OrdersDownload())->batch();
    }
}

