# pcbecom

> E-commerce construido con Laravel 12 + Vue 3 + Inertia.js, integrado con la API de SYSCOM para la venta de productos electrónicos en México.

![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-%5E8.2-777BB4?logo=php&logoColor=white)
![Vue](https://img.shields.io/badge/Vue-3.x-4FC08D?logo=vue.js&logoColor=white)
![Inertia](https://img.shields.io/badge/Inertia.js-2.x-9553E9)
![Vite](https://img.shields.io/badge/Vite-7.x-646CFF?logo=vite&logoColor=white)
![Tailwind](https://img.shields.io/badge/TailwindCSS-4.x-38B2AC?logo=tailwind-css&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)
![Build](https://img.shields.io/badge/Build-passing-brightgreen)

---

## Acerca del proyecto

**pcbecom** es una tienda en línea interna que consume el catálogo de **SYSCOM** (distribuidor mexicano de productos electrónicos y de redes) para ofrecer a nuestros clientes un catálogo navegable, carrito persistente, checkout seguro y consulta de pedidos.

La aplicación está pensada como vitrina de storefront: el inventario, precios base y existencias se obtienen en tiempo real desde la API de SYSCOM; pcbecom calcula el precio final aplicando una comisión configurable.

---

## Características

- **Catálogo dinámico** con listado por categorías y búsqueda.
- **Detalle de producto** con existencias y precios en tiempo real (vía SYSCOM).
- **Carrito persistente** tanto para invitados (vía sesión) como para usuarios autenticados.
- **Checkout** con clave de idempotencia para evitar cobros duplicados.
- **Órdenes** con número de seguimiento y consulta posterior sin autenticación.
- **Vistos recientemente** para usuarios con sesión.
- **Autenticación completa** (registro, login, recuperación de contraseña, verificación de email) con Laravel Breeze + Sanctum.
- **Conversión de moneda** USD → MXN con tasa configurable.
- **Comisión configurable** aplicada al precio base de SYSCOM.
- **Panel de perfil** para gestión de datos del usuario y contraseña.

---

## Stack tecnológico

| Capa            | Tecnología                                       |
|-----------------|--------------------------------------------------|
| Lenguaje        | PHP ^8.2                                         |
| Framework       | Laravel 12                                       |
| Frontend        | Vue 3 + Inertia.js 2                             |
| Estilos         | Tailwind CSS 4                                   |
| Bundler         | Vite 7                                           |
| Iconos          | Lucide Vue Next                                  |
| Bridge          | Tighten Ziggy (rutas Laravel en JS)               |
| Auth API        | Laravel Sanctum                                  |
| Base de datos   | MySQL 8 / MariaDB 10.6+                          |
| Cola            | Database driver (configurable a Redis)            |
| Cache           | Database driver (configurable a Redis)           |
| Testing         | PHPUnit 11                                       |
| Linter          | Laravel Pint                                     |

---

## Requisitos previos

- **PHP 8.2+** con extensiones: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `tokenizer`, `xml`.
- **Composer 2.x**.
- **Node.js 20+** y **npm** (o pnpm).
- **MySQL 8** o **MariaDB 10.6+** corriendo localmente.
- **Cuenta de desarrollador en SYSCOM** para obtener `SYSCOM_CLIENT_ID` y `SYSCOM_CLIENT_SECRET` (https://api.developer.com).

---

## Instalación

```bash
# 1. Clonar el repositorio
git clone <url-del-repositorio> pcbecom
cd pcbecom

# 2. Instalar dependencias de PHP
composer install

# 3. Instalar dependencias de JS
npm install

# 4. Crear el archivo de entorno
cp .env.example .env

# 5. Generar la clave de aplicación
php artisan key:generate

# 6. Crear la base de datos (en MySQL)
mysql -u root -p -e "CREATE DATABASE pcbecom CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 7. Ejecutar migraciones (y seeders si los hay)
php artisan migrate --seed

# 8. Compilar assets para producción
npm run build

# 9. Levantar el servidor de desarrollo
php artisan serve
```

> Si usas **Laragon**, registra el proyecto y accede en `http://pcbecom.test` (la URL ya está preconfigurada en `.env.example` como `APP_URL=http://pcbecom.test`).

### Modo desarrollo con un solo comando

`composer run dev` levanta simultáneamente el servidor PHP, el worker de colas, los logs en vivo (Pail) y Vite con recarga en caliente:

```bash
composer run dev
```

---

## Configuración (.env)

Variables principales a ajustar antes del primer arranque:

| Variable              | Descripción                                                                 |
|-----------------------|-----------------------------------------------------------------------------|
| `APP_NAME`            | Nombre mostrado de la aplicación (`pcbecom`).                               |
| `APP_URL`             | URL pública (`http://pcbecom.test` en Laragon).                             |
| `APP_LOCALE`          | Locale (`es` por defecto).                                                 |
| `DB_*`                | Conexión MySQL (`host`, `port`, `database`, `username`, `password`).        |
| `SESSION_DRIVER`      | Persistencia de sesión (`database` por defecto).                            |
| `CACHE_STORE`         | Driver de cache (`database` por defecto).                                   |
| `QUEUE_CONNECTION`    | Driver de colas (`database` por defecto).                                   |
| `MAIL_MAILER`         | Driver de correo (`log`, `smtp`, etc.).                                     |
| `SYSCOM_BASE_URL`     | URL base del portal SYSCOM.                                                |
| `SYSCOM_API_URL`      | URL base de la API de SYSCOM (`https://api.developer.com` por defecto).     |
| `SYSCOM_CLIENT_ID`    | Client ID de tu app en SYSCOM Developer.                                   |
| `SYSCOM_CLIENT_SECRET`| Client Secret de tu app en SYSCOM Developer.                               |
| `SYSCOM_COMMISSION_RATE` | Comisión decimal aplicada al precio base (ej. `0.20` = 20 %).            |
| `MAIN_PAGE_URL`       | URL canónica del storefront.                                               |

---

## Uso

| Comando                      | Descripción                                              |
|------------------------------|----------------------------------------------------------|
| `php artisan serve`          | Servidor de desarrollo en `http://127.0.0.1:8000`.       |
| `composer run dev`           | Servidor + queue + logs + Vite (todo en paralelo).       |
| `npm run dev`                | Solo Vite en modo HMR.                                   |
| `npm run build`              | Compila assets para producción.                          |
| `php artisan migrate`        | Ejecuta migraciones pendientes.                          |
| `php artisan migrate:fresh --seed` | Recrea la BD desde cero con datos de prueba.      |
| `php artisan test`           | Corre la suite de tests (PHPUnit).                       |
| `vendor/bin/pint`            | Formatea el código según las reglas de Laravel Pint.     |
| `php artisan queue:work`     | Procesa trabajos en cola (necesario en producción).      |

---

## Estructura del proyecto

```
pcbecom/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/                 # Login, registro, verificación...
│   │   │   ├── Syscom/               # Controladores integración SYSCOM
│   │   │   └── Webhooks/             # Endpoints de webhooks
│   │   ├── Middleware/               # Middleware personalizado
│   │   ├── Requests/                 # Form requests
│   │   └── Resources/                # API resources
│   ├── Jobs/                         # Jobs en cola
│   ├── Listeners/                    # Listeners de eventos
│   ├── Models/
│   │   ├── User.php
│   │   ├── Cart.php
│   │   ├── CartItem.php
│   │   ├── Order.php
│   │   └── OrderItem.php
│   ├── Providers/
│   └── Services/
│       ├── Cart/                     # Lógica de carrito
│       ├── Currency/                 # Conversión de moneda
│       ├── Payment/                  # Procesadores de pago
│       └── Syscom/                   # Cliente SYSCOM + DTOs
├── bootstrap/
├── config/
├── database/
│   ├── factories/
│   ├── migrations/                   # users, cache, jobs, carts, orders...
│   └── seeders/
├── public/
├── resources/
│   ├── css/
│   ├── js/
│   │   ├── Pages/                    # Inertia pages (Vue)
│   │   │   ├── Index.vue             #   - Catálogo principal
│   │   │   ├── Products/Show.vue     #   - Detalle de producto
│   │   │   ├── Cart/Index.vue        #   - Carrito
│   │   │   ├── Checkout/Index.vue    #   - Checkout
│   │   │   ├── Orders/Show.vue       #   - Detalle de orden
│   │   │   └── Catalog/RecentlyViewed.vue
│   │   ├── Components/               # Componentes reutilizables
│   │   ├── Layouts/                  # Layouts Inertia
│   │   └── app.js                    # Entry point
│   └── views/
├── routes/
│   ├── api.php                       # Endpoints API (Sanctum)
│   ├── auth.php                      # Rutas de autenticación
│   ├── console.php
│   └── web.php                       # Rutas web (Inertia)
├── storage/
├── tests/
│   ├── Feature/
│   └── Unit/
├── .env.example
├── artisan
├── composer.json
├── package.json
├── phpunit.xml
├── tailwind.config.js
└── vite.config.js
```

---

## Integración con SYSCOM

La capa de integración vive en `app/Services/Syscom/` (cliente HTTP y DTOs) y `app/Http/Controllers/Syscom/` (endpoints que exponen esa información al frontend a través de Inertia/Ziggy).

**Flujo general:**

1. `App\Services\Syscom\SyscomClient` obtiene un token OAuth contra `SYSCOM_API_URL`.
2. Consume catálogo, categorías, productos y existencias.
3. El precio mostrado al usuario se calcula como:
   ```
   precio_final = precio_syscom * (1 + SYSCOM_COMMISSION_RATE)
   ```
   y luego se convierte de USD a MXN con el servicio de moneda.
4. Los resultados se sirven al frontend vía Inertia props.

Para usar la integración es indispensable definir `SYSCOM_CLIENT_ID`, `SYSCOM_CLIENT_SECRET` y `SYSCOM_API_URL` en `.env`.

---

## Testing

```bash
php artisan test
```

Las pruebas usan **PHPUnit 11**. Estructura:

- `tests/Feature/` — pruebas de extremo a extremo (HTTP, BD, integración).
- `tests/Unit/` — pruebas unitarias de servicios y modelos.

Configuración en `phpunit.xml`.

---

## Despliegue

Recomendaciones mínimas para producción:

```bash
# 1. Compilar assets
npm run build

# 2. Optimizar Laravel
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 3. Variables de entorno
APP_ENV=production
APP_DEBUG=false

# 4. Worker de colas (systemd / supervisor)
php artisan queue:work --tries=3

# 5. Cron para el scheduler
* * * * * cd /ruta/a/pcbecom && php artisan schedule:run >> /dev/null 2>&1
```

Servidor sugerido: **Nginx + PHP-FPM** (o Apache con `mod_rewrite`). Asegurar permisos de escritura para `storage/` y `bootstrap/cache/`.

---

## Roadmap

El plan de sprints del proyecto está documentado en [`SPRINTS.md`](./SPRINTS.md).

---

## Contribución

1. Haz fork del repositorio.
2. Crea una rama con el prefijo correspondiente: `feat/...`, `fix/...`, `chore/...`.
3. Ejecuta `vendor/bin/pint` antes de commit.
4. Asegúrate de que `php artisan test` pase.
5. Abre un Pull Request describiendo el cambio.

---

## Licencia

Este proyecto se distribuye bajo la licencia **MIT**.

---

## Autor

**pcbecom** — PCBTroniks
