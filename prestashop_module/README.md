# Módulo FacturaScripts para PrestaShop

Módulo de integración completa entre PrestaShop y FacturaScripts 2025.

## 🚀 Características

- ✅ **Webhooks en tiempo real**: Envía notificaciones automáticas a FacturaScripts cuando se crea o actualiza un pedido
- ✅ **Descarga de facturas**: Permite descargar las facturas PDF generadas en FacturaScripts desde PrestaShop
- ✅ **Botones en backoffice**: Muestra botón de descarga en la ficha del pedido (admin)
- ✅ **Botones en front**: Muestra botón de descarga en el detalle del pedido (cuenta del cliente)
- ✅ **Tracking automático**: Guarda la relación entre pedidos PrestaShop y facturas FacturaScripts

## 📋 Requisitos

- PrestaShop 1.7.0 o superior
- FacturaScripts 2024.5 o superior con el plugin de PrestaShop instalado
- PHP 7.2 o superior
- cURL activado en PHP

## 📦 Instalación

### 1. Instalar el módulo en PrestaShop

1. Descarga el módulo o copia la carpeta `fsfacturascripts` a:
   ```
   /modules/fsfacturascripts/
   ```

2. Ve al backoffice de PrestaShop: **Módulos > Module Manager**

3. Busca "FacturaScripts Integration"

4. Haz clic en **Instalar**

### 2. Configurar el módulo

1. Después de instalar, haz clic en **Configurar**

2. Completa los campos:
   - **URL de FacturaScripts**: La URL completa de tu instalación (ej: `https://mitienda.com`)
   - **Token Webhook**: Token generado en FacturaScripts (ver paso 3)
   - **Activar Webhooks**: Activa para enviar notificaciones automáticas

3. Haz clic en **Guardar**

### 3. Obtener el Token en FacturaScripts

1. En FacturaScripts, ve a: **Configuración PrestaShop > Webhooks**

2. Activa los webhooks

3. Copia el token que aparece (32 caracteres)

4. Pega este token en la configuración del módulo PrestaShop

## ⚙️ Funcionamiento

### Flujo de Webhooks

1. **Cliente hace un pedido** en PrestaShop
2. **PrestaShop envía webhook** a FacturaScripts automáticamente
3. **FacturaScripts importa el pedido** como albarán
4. **Si se genera factura**, FacturaScripts devuelve el ID en la respuesta
5. **PrestaShop guarda el ID** en su base de datos

### Flujo de Descarga de Facturas

1. **Usuario accede** a la ficha del pedido (admin o cliente)
2. **PrestaShop verifica** si existe factura en FacturaScripts
3. **Si existe**, muestra botón "Descargar Factura PDF"
4. **Al hacer clic**, solicita el PDF a FacturaScripts usando token seguro
5. **FacturaScripts genera y devuelve** el PDF de la factura

## 📍 Ubicaciones de los botones de descarga

### Backoffice (Administración)

- ✅ **Ficha del pedido**: Panel lateral con botón de descarga
- 🔜 **Listado de pedidos**: Columna extra con icono de descarga (próximamente)

### Front (Cliente)

- ✅ **Detalle del pedido**: Caja con información de la factura y botón de descarga
- ✅ **Listado en cuenta**: Icono/enlace en cada pedido (implementado en el hook)

## 🗄️ Estructura de la Base de Datos

El módulo crea una tabla `ps_fs_facturascripts` con:

| Campo              | Tipo         | Descripción                          |
|--------------------|--------------|--------------------------------------|
| id_fs_facturascripts | INT        | ID autoincremental                   |
| id_order           | INT          | ID del pedido PrestaShop             |
| order_reference    | VARCHAR(64)  | Referencia del pedido                |
| fs_albaran_id      | INT          | ID del albarán en FacturaScripts     |
| fs_factura_id      | INT          | ID de la factura en FacturaScripts   |
| fs_factura_code    | VARCHAR(64)  | Código de la factura (ej: FAC001)    |
| webhook_sent       | TINYINT      | Si se envió el webhook (0/1)         |
| webhook_response   | TEXT         | Respuesta del webhook                |
| date_add           | DATETIME     | Fecha de creación                    |
| date_upd           | DATETIME     | Fecha de actualización               |

## 🔒 Seguridad

- ✅ Usa token de 32 caracteres para autenticación
- ✅ Todas las peticiones son verificadas por FacturaScripts
- ✅ No expone datos sensibles en URLs públicas
- ✅ PDFs solo se generan si el token es válido

## 🐛 Solución de Problemas

### Los webhooks no se envían

1. Verifica que "Activar Webhooks" esté marcado
2. Comprueba que la URL de FacturaScripts sea correcta
3. Verifica que el token sea el correcto
4. Revisa los logs de PrestaShop en: **Parámetros Avanzados > Logs**

### No aparece el botón de descarga

1. Verifica que el pedido se haya importado en FacturaScripts
2. Comprueba que el albarán tenga una factura asociada
3. Revisa que el token esté configurado correctamente

### Error al descargar PDF

1. Verifica que la URL de FacturaScripts sea accesible desde PrestaShop
2. Comprueba que el token sea válido
3. Verifica que la factura exista en FacturaScripts

## 📝 Changelog

### v1.0.0 (2025-01-XX)
- ✨ Primera versión del módulo
- ✅ Webhooks en tiempo real
- ✅ Descarga de facturas PDF
- ✅ Botones en backoffice y front

## 📄 Licencia

MIT License - Uso libre

## 🤝 Soporte

Para reportar bugs o solicitar nuevas funcionalidades, contacta con el equipo de FacturaScripts.

---

**Desarrollado por FacturaScripts Team** | [https://facturascripts.com](https://facturascripts.com)
