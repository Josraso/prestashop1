<?php

namespace FacturaScripts\Plugins\Prestashop\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;

/**
 * Modelo para la configuración de PrestaShop
 */
class PrestashopConfig extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var string */
    public $shop_url;

    /** @var string */
    public $api_key;

    /** @var string */
    public $codalmacen;

    /** @var string */
    public $codserie;

    /** @var string */
    public $estados_importar;

    /** @var string */
    public $import_since_date;

    /** @var int */
    public $import_since_id;

    /** @var bool */
    public $activo;

    /** @var bool */
    public $use_ws_key_param;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'prestashop_config';
    }

    public function clear(): void
    {
        parent::clear();
        $this->activo = true;
        $this->estados_importar = '';
        $this->import_since_id = 0; // Por defecto, importar desde el principio
        $this->import_since_date = ''; // Fecha fija desde la que siempre buscar
        $this->use_ws_key_param = false; // Por defecto, usar Basic Auth
    }

    /**
     * Obtiene los estados a importar como array
     */
    public function getEstadosArray(): array
    {
        if (empty($this->estados_importar)) {
            return [];
        }

        $estados = json_decode($this->estados_importar, true);
        return is_array($estados) ? $estados : [];
    }

    /**
     * Establece los estados a importar desde un array
     */
    public function setEstadosArray(array $estados): void
    {
        $this->estados_importar = json_encode($estados);
    }

    /**
     * Obtiene la configuración activa
     */
    public static function getActive(): ?self
    {
        $config = new self();
        $where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('activo', true)];

        if ($config->loadFromCode('', $where)) {
            return $config;
        }

        return null;
    }
}
