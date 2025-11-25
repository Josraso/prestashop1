<?php

namespace FacturaScripts\Plugins\Prestashop;

use FacturaScripts\Core\Template\InitClass;

require_once __DIR__.'/vendor/autoload.php';

class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new Extension\Controller\EditAlbaranCliente());
    }

    public function update(): void
    {
        // Ejecutar el instalador para crear productos necesarios
        // Se ejecuta en cada actualización para asegurar que los productos existen
        $installer = new Extension\Controller\Installer();
        $installer->install();
    }

    public function uninstall(): void
    {
        // Ejecutar el desinstalador
        $installer = new Extension\Controller\Installer();
        $installer->uninstall();
    }
}
