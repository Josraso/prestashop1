<?php

namespace FacturaScripts\Plugins\Prestashop\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopConfig;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopImportLog;
use FacturaScripts\Plugins\Prestashop\Lib\Actions\OrdersDownload;

/**
 * Dashboard de PrestaShop - Estadísticas y control de importaciones
 */
class DashboardPrestashop extends Controller
{
    /** @var array */
    public $statsToday = [];

    /** @var array */
    public $statsWeek = [];

    /** @var array */
    public $statsMonth = [];

    /** @var array */
    public $importsSuccess = [];

    /** @var array */
    public $importsSkipped = [];

    /** @var array */
    public $importsError = [];

    /** @var PrestashopConfig */
    public $config;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'Dashboard PrestaShop';
        $data['icon'] = 'fas fa-chart-line';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        // Cargar configuración
        $this->config = PrestashopConfig::getActive();

        // Procesar acciones
        $action = $this->request->request->get('action', '');
        if ($action === 'import-now') {
            $this->importNowAction();
        }

        // Cargar estadísticas
        $this->loadStats();
        $this->loadImportsByResult();
    }

    /**
     * Carga las estadísticas por período
     */
    private function loadStats(): void
    {
        $this->statsToday = PrestashopImportLog::getStats('today');
        $this->statsWeek = PrestashopImportLog::getStats('week');
        $this->statsMonth = PrestashopImportLog::getStats('month');
    }

    /**
     * Carga importaciones separadas por resultado
     */
    private function loadImportsByResult(): void
    {
        $logModel = new PrestashopImportLog();
        $order = ['fecha' => 'DESC', 'hora' => 'DESC'];

        // Importados correctamente
        $whereSuccess = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('resultado', 'success')];
        $this->importsSuccess = $logModel->all($whereSuccess, $order, 0, 50);

        // Omitidos (ya importados o por fecha)
        $whereSkipped = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('resultado', 'skipped')];
        $this->importsSkipped = $logModel->all($whereSkipped, $order, 0, 50);

        // Errores
        $whereError = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('resultado', 'error')];
        $this->importsError = $logModel->all($whereError, $order, 0, 50);
    }

    /**
     * Ejecuta importación manual inmediata
     */
    private function importNowAction(): void
    {
        if (!$this->permissions->allowUpdate) {
            Tools::log()->warning('No tienes permisos para ejecutar la importación');
            return;
        }

        if (!$this->config) {
            Tools::log()->error('PrestaShop no está configurado');
            return;
        }

        try {
            Tools::log()->info('========================================');
            Tools::log()->info('IMPORTACIÓN MANUAL DESDE DASHBOARD');
            Tools::log()->info('========================================');

            $importer = new OrdersDownload();
            $importer->batch('manual'); // Origen: manual

            Tools::log()->info('Importación manual completada. Revisa las estadísticas actualizadas.');

            // Recargar estadísticas después de la importación
            $this->loadStats();
            $this->loadImportsByResult();

        } catch (\Exception $e) {
            Tools::log()->error('Error en importación manual: ' . $e->getMessage());
        }
    }

    /**
     * Obtiene el color del badge según el resultado
     */
    public function getBadgeClass(string $resultado): string
    {
        switch ($resultado) {
            case 'success':
                return 'badge-success';
            case 'error':
                return 'badge-danger';
            case 'skipped':
                return 'badge-warning';
            default:
                return 'badge-secondary';
        }
    }

    /**
     * Obtiene el texto del badge según el resultado
     */
    public function getBadgeText(string $resultado): string
    {
        switch ($resultado) {
            case 'success':
                return 'Importado';
            case 'error':
                return 'Error';
            case 'skipped':
                return 'Omitido';
            default:
                return $resultado;
        }
    }
}
