# Use a multi-region database in a Laravel application

In this guide we'll learn how to use Fly's ability to [replay requests](https://fly.io/blog/globally-distributed-postgres/) in order to improve the performance of a Laravel application.

For queries that involve writing to the database, we will get Laravel to _replay_ those requests in a different region: the region the primary database is located in. We will use a read replica for queries that only involve fetching data. The read replica is much closer to the application and so has much lower latency.

**Note:** To avoid replicating the steps needed to package a Laravel application to run on Fly's global application platform, please see [fly-hello-laravel](https://github.com/fly-apps/fly-hello-laravel).

This guide assumes you have _already_ added the files needed to package it ready to run on Fly. It only documents the changes needed to use a _multi-region_ database, and goes onto demonstrate using them within a sample application.

***

### Required changes

You will need to make the following changes:

#### fly.toml

If you recall our previous guide for how to deploy a Laravel application on Fly, this file supports an `[env]` section for environment variables. You need to add two more.

Set the _DB_CONNECTION_ as _"pgsql"_ to use PostgreSQL. And you will need to specify the region your primary database is in (assuming you already have one) by setting _PRIMARY_REGION_:

```toml
DB_CONNECTION = "pgsql"
PRIMARY_REGION = "scl"
```

#### config/services.php

You can choose where to put the Fly variables within your config folder. You may prefer to add a separate `fly.php` file within the `config` folder. In our case, we added the Fly variables to the existing `services.php` file which contains other third-party services:

```php
'fly' => [
    'primary_region' => env('PRIMARY_REGION', ''), // set in fly.toml
    'fly_region' => env('FLY_REGION', ''), // set by Fly at runtime
]
```

The `PRIMARY_REGION` was mentioned above: it's the region the primary database is in. That's where writes are sent.

The `FLY_REGION` is set by Fly at runtime. Our application needs to know where it is being run from to know whether it needs to replay requests to the database.

Why do we need to use a config file? We need to be able to access Fly environment variables within our application. Laravel's `env()` function should only be used within config files. So we need to use the config helper or facade to access environment variables _outside_ of config files.

#### app/Exceptions/Handler.php

This is the most important change you need to make. Handily PostgreSQL sends a read-only transaction error if you write to a read replica. We need to catch that error. That is what _this_ file does. Since a database can throw different kinds of errors, we need to look out for one that contains `SQLSTATE[25006]`.

If we catch one, we don't report it (you _can_, however your logs will fill up with these requests. They are not errors we need to know about because we are handling them). And hence we return `false` from the `reportable` handler.

```php
$this->reportable(function (Throwable $e) {
    if ($e instanceof QueryException || $e instanceof PDOException) {
        if (str_contains($e->getMessage(), 'SQLSTATE[25006]')) {
            return false;
        }
    }
});
```

Next, we need to replay it. That's what the `renderable` handler in this file does. Notice how we are extracting the variables for the regions from the config. Here we are using the config helper provided by Laravel. You could use the facade if you prefer. The name is based on where we stored the values. In our case, it was in `services.php` within a `fly` array key, and so we get the values using a `services.fly.` prefix.

We see if we know the region the code is running in _and_ we know the region the primary database is in (which we should, as both are provided as environment variables), then are we running _in_ the primary region right now? If not, we replay the request _in_ the primary region. We do that by returning a special `fly-replay` header which tells Fly the region the request should be run in:

```php
$fly_region = config('services.fly.fly_region', false);
$primary_region = config('services.fly.primary_region', false);
if ($fly_region && $primary_region && $fly_region !== $primary_region) {
    return response('Replaying request in ' . $primary_region, 409, [
        'fly-replay' => 'region='  . $primary_region,
        'content-type' => 'text/plain'
    ]);
}
```

#### config/database.php

The application needs to know the database connection settings (the host, port, and so on). Fly provides a single `DATABASE_URL` in an environment variable.

We need to apply some additional logic to decide whether to use that as-is (which will connect to the primary database using port 5432) _or_ whether we should instead connect to a read replica (using port 5433).

The logic we use is the same as in the exception `Handler.php` file: if we know the region the vm is in _and_ we know the region the primary databse is in, we compare them. If the vm is _not_ in the same region as the primary database, we connect to the read replica. Else we connect to the primary database.

The result is that we get the best read performance as we will always connect to the closest database. And we will improve write performance too since writes will hit the nearby read replica, fail, _but_ then be replayed by Fly in the region the primary database is in.

```php
'pgsql' => [
    'driver' => 'pgsql',
    'url' => env('FLY_REGION', false) && env('PRIMARY_REGION', false) && env('FLY_REGION', false) !== env('PRIMARY_REGION', false) ? str_replace(':5432/', ':5433/', env('DATABASE_URL')) : env('DATABASE_URL'),
    'host' => env('DB_HOST', ''),
    'port' => env('DB_PORT', ''),
    'database' => env('DB_DATABASE', ''),
    'username' => env('DB_USERNAME', ''),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => 'prefer'
],
```

***

### Optional changes

You don't have to make these changes to your Laravel application. But they may be useful.

#### app/Http/Middleware/FlyHeaders.php

This middleware adds a `fly-region` header to every response. That helps see if we are being served from the expected closest region and whether database writes are being corrected replayed:

```php
public function handle(Request $request, Closure $next)
{
    $response = $next($request);
    $response->header('fly-region', config('services.fly.fly_region'));

    return $response;
}
```

#### app/Http/Kernel.php

If you are using middleware to add a `fly-region` header, we likely want that to run for every HTTP request. So add it to the global stack (the last line below). Your middleware array will likely contain different ones though:

```php
/**
  * The application's global HTTP middleware stack.
  *
  * These middleware are run during every request to your application.
  *
  * @var array<int, class-string|string>
  */
protected $middleware = [
    // \App\Http\Middleware\TrustHosts::class,
    \App\Http\Middleware\TrustProxies::class,
    \Illuminate\Http\Middleware\HandleCors::class,
    \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
    \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
    \App\Http\Middleware\TrimStrings::class,
    \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    \App\Http\Middleware\FlyHeaders::class // added
];
```

We _could_ improve this further by adding additional middleware to help avoid inconsistencies. Since the downside of writing to a different database than you are reading from is that a subsequent HTTP request may read stale data which has since changed. A way to avoid that would be to return a cookie in the response which contains a threshold time during which read-requests should be fetched from the same database. That trades speed for consitency so it would depend on the requirements of your application whether that was important or even required.

**Note:** If you _already_ have a Laravel application, you can stop here. However if you would like to see these changes applied to a sample application, please continue.

***

### A sample application

We built a sample Laravel application to demonstrate using the `fly-replay` header, It reads and writes random strings to the database. That way we can see how fast reads and writes are.

So of course you won't make _these_ changes to an existing Laravel application.

#### database/factories/ItemFactory.php

A simple factory to make an item.

#### database/migrations/2022_04_02_183127_create_items_table.php

The migration to add an `items` table, with a `name` column.

#### routes/web.php

We added two routes to test reading and writing. Both are GET to make calling them in a browser simpler:

```php
Route::get('/read', [ItemController::class, 'index']);
Route::get('/write', [ItemController::class, 'store']);
```

#### app/Http/Controllers/ItemController.php

The `index` method reads from the `items` table using the query builder:

```php
$items = DB::table('items')->orderByDesc('created_at')->limit(5)->get();
```

The `store` method writes to the `items` table using the query builder:

```php
DB::table('items')->insert([
    'name' => $name,
    'created_at' => \Carbon\Carbon::now(),
    'updated_at' => \Carbon\Carbon::now()
]);
```

#### app/Models/Item.php

A model to match the `items` table.

#### resources/views/read.blade.php

A simple page extending the layout showing the read items, with latency.

#### resources/views/write.blade.php

A simple page extending the layout showing the written item, with latency.

***

### Deploy the sample application to Fly

To test the difference replaying requests makes, we created a primary database far away and then created a nearby read-replica. We can then test how quickly we can read and write to that database.

If you haven't already done so, [install the Fly CLI](https://fly.io/docs/getting-started/installing-flyctl/) and then [log in to Fly](https://fly.io/docs/getting-started/log-in-to-fly/).

You need to have already [created a multi-region PostgreSQL database](https://fly.io/docs/getting-started/multi-region-databases/).

Next (assuming you are using the provided `fly.toml` file) you will need to update that to have _your_ app's name, URL and primary region (which will be different to ours):

```toml
name = "your-app-name"

APP_URL = "https://your-app-name.fly.dev"

PRIMARY_REGION = "scl"
```

Run `fly launch` from the application's directory. The CLI will see there is an existing `fly.toml`. When it asks if you want to copy that, say _Yes_.

The CLI will then spot the `Dockerfile`.

You'll be prompted to choose an organization. They are used to share resources between Fly users. Since every Fly user has a personal organization, you could pick that. It needs to be the same one your database is in.

You will be asked for the region to deploy the application in. Pick one closest to you for the best performance.

It will ask if you want a database. Say _No_ as you already have one.

It will then prompt you to deploy now. Say _No_. Why? In production your Laravel application needs to have a secret key. If you were to deploy right now, you would see errors in the logs along the lines of:

> No application encryption key has been specified. {"exception":"[object] (Illuminate\\Encryption\\MissingAppKeyException"

Get the `APP_KEY` from your `.env` file (you can generate a new one using `php artisan key:generate`).

Then run `fly secrets set APP_KEY=the-value-of-the-secret-key`. That will stage that secret in Fly, ready to deploy it.

Now you can go ahead and run `fly deploy`.

```
...
--> Building image done
==> Pushing image to fly
...
1 desired, 1 placed, 1 healthy, 0 unhealthy [health checks: 2 total, 2 passing]
--> v1 deployed successfully
```

You should see the build progress, the healthchecks pass, and a message to confirm the application was successfully deployed.

Once it is deployed, sure to attach your multi-region PostgreSQL database _to_ the app. This populates the `DATABASE_URL` environment variable which is the one thing currently missing. So replace this with the name of your database:

`fly pg attach --postgres-app your-database-name-goes-here`

Next, for the database reads/writes to work as expected, your app needs at least one vm in the same region as your primary database. To do that we'll run `fly regions set lhr scl`.

Check those are the only regions listed by using `fly regions list`.

Now we'll scale our test app to have _two_ vms: one nearby in `lhr` (UK, where we are) and one in `scl` (Chile, where our primary database is in order to demonstrate the latency of having a primary database that's far away) by running `fly scale count 2`.

**Note:** There must be a vm running in the primary region (the one you set above, as `PRIMARY_REGION`). You can not (currently) use this approach with auto-scaling enabled.

You can see its status using `fly status`. You should now see two vms, in the chosen regions. It may take a minute for the new one to start running:

```
Instances
ID              PROCESS VERSION REGION  DESIRED STATUS  HEALTH CHECKS           RESTARTS        CREATED
abcdefgh        app     2       lhr     run     running 2 total, 2 passing      0               1m10s ago
abcdefg1        app     2       scl     run     running 2 total, 2 passing      0               1m11s ago
```

You should now be able to visit `https://your-app-name.fly.dev` and see Laravel's default home page.

### Run a database migration

At this point the sample application should be connected to the empty database. However there isn't an `items` table within it. Our example app expects there to be. And so its `/read` and `/write` routes won't work (they will likely return a _500_ error).

We _could_ have run the database migration on deploy but you can also run it using an SSH console on the vm:

```
fly ssh console
cd /var/www/html
php artisan migrate
```

Assuming the app was able to connect to the database, you should see the tables being created. We've left the default Laravel ones and added an `items` one.

### Try a database read and write

Our example application has a `/read` and `/write` route to test their performance. Normally writes would likely not be done during a `GET` request, however using one makes it simpler to try using a normal web browser.

To compare their speed we used a simple test using [k6](https://k6.io/):

```js
import http from 'k6/http';
import { check, sleep } from 'k6';

export default function () {
  const res = http.get('https://app-name-here.fly.dev/read');
  check(res, { 'status was 200': (r) => r.status == 200 });
}
```

We then run it, specifying the users and duration. We used the most basic test here: 1 user making requests for 10 seconds:

```
k6 run --vus 1 --duration 10s script.js
```

To see the improvement made by using a read replica for reads and the `fly-replay` header for writes, we tried the `/read` and `/write` URLs using a single primary database. So a single `DATABASE_URL` (using port 5432) in `config/database.php` without any logic. That was therefore used by the application regardless of where in the world the request was being made.

One key metric to look at is the `http_req_duration` line. The `avg=X` shows the average time per-request.

### Reads WITHOUT using a read replica

```
k6 run --vus 1 --duration 10s script.js

          /\      |??????| /??????/   /??????/
     /\  /  \     |  |/  /   /  /
    /  \/    \    |     (   /   ??????\
   /          \   |  |\  \ |  (???)  |
  / __________ \  |__| \__\ \_____/ .io

  execution: local
     script: script.js
     output: -

  scenarios: (100.00%) 1 scenario, 1 max VUs, 40s max duration (incl. graceful stop):
           * default: 1 looping VUs for 10s (gracefulStop: 30s)


running (12.5s), 0/1 VUs, 4 complete and 0 interrupted iterations
default ??? [======================================] 1 VUs  10s

     ??? status was 200

     checks.........................: 100.00% ??? 4        ??? 0
     data_received..................: 11 kB   876 B/s
     data_sent......................: 669 B   53 B/s
     http_req_blocked...............: avg=66.85ms  min=1??s   med=1??s     max=267.4ms  p(90)=187.18ms p(95)=227.29ms
     http_req_connecting............: avg=5.39ms   min=0s    med=0s      max=21.58ms  p(90)=15.1ms   p(95)=18.34ms
     http_req_duration..............: avg=3.06s    min=3s    med=3.06s   max=3.14s    p(90)=3.12s    p(95)=3.13s
       { expected_response:true }...: avg=3.06s    min=3s    med=3.06s   max=3.14s    p(90)=3.12s    p(95)=3.13s
     http_req_failed................: 0.00%   ??? 0        ??? 4
     http_req_receiving.............: avg=501.25??s min=136??s med=496.5??s max=876??s    p(90)=846.9??s  p(95)=861.45??s
     http_req_sending...............: avg=157.5??s  min=123??s med=139.5??s max=228??s    p(90)=202.5??s  p(95)=215.24??s
     http_req_tls_handshaking.......: avg=60.96ms  min=0s    med=0s      max=243.85ms p(90)=170.69ms p(95)=207.27ms
     http_req_waiting...............: avg=3.06s    min=3s    med=3.06s   max=3.14s    p(90)=3.11s    p(95)=3.12s
     http_reqs......................: 4       0.318759/s
     iteration_duration.............: avg=3.13s    min=3s    med=3.1s    max=3.33s    p(90)=3.27s    p(95)=3.3s
     iterations.....................: 4       0.318759/s
     vus............................: 1       min=1      max=1
     vus_max........................: 1       min=1      max=1
```

### Reads using a read replica

```
k6 run --vus 1 --duration 10s script.js

          /\      |??????| /??????/   /??????/
     /\  /  \     |  |/  /   /  /
    /  \/    \    |     (   /   ??????\
   /          \   |  |\  \ |  (???)  |
  / __________ \  |__| \__\ \_____/ .io

  execution: local
     script: script.js
     output: -

  scenarios: (100.00%) 1 scenario, 1 max VUs, 40s max duration (incl. graceful stop):
           * default: 1 looping VUs for 10s (gracefulStop: 30s)


running (10.0s), 0/1 VUs, 176 complete and 0 interrupted iterations
default ??? [======================================] 1 VUs  10s

     ??? status was 200

     checks.........................: 100.00% ??? 176       ??? 0
     data_received..................: 289 kB  29 kB/s
     data_sent......................: 6.9 kB  685 B/s
     http_req_blocked...............: avg=1.62ms   min=0s      med=1??s     max=285.86ms p(90)=2??s     p(95)=2??s
     http_req_connecting............: avg=135.11??s min=0s      med=0s      max=23.78ms  p(90)=0s      p(95)=0s
     http_req_duration..............: avg=54.93ms  min=48.5ms  med=53.59ms max=113.04ms p(90)=59.22ms p(95)=62.74ms
       { expected_response:true }...: avg=54.93ms  min=48.5ms  med=53.59ms max=113.04ms p(90)=59.22ms p(95)=62.74ms
     http_req_failed................: 0.00%   ??? 0         ??? 176
     http_req_receiving.............: avg=1.09ms   min=127??s   med=807??s   max=10.97ms  p(90)=1.43ms  p(95)=2.05ms
     http_req_sending...............: avg=141.13??s min=58??s    med=137.5??s max=309??s    p(90)=186??s   p(95)=214.5??s
     http_req_tls_handshaking.......: avg=1.46ms   min=0s      med=0s      max=257.74ms p(90)=0s      p(95)=0s
     http_req_waiting...............: avg=53.69ms  min=47.36ms med=52.64ms max=112.51ms p(90)=57.83ms p(95)=60.04ms
     http_reqs......................: 176     17.558958/s
     iteration_duration.............: avg=56.9ms   min=49ms    med=53.88ms max=399.61ms p(90)=59.49ms p(95)=63.1ms
     iterations.....................: 176     17.558958/s
     vus............................: 1       min=1       max=1
     vus_max........................: 1       min=1       max=1
```

As you can see, using a read replica dramatically reduced the average request duration.

### Writes WITHOUT using fly-replay

```
k6 run --vus 1 --duration 10s script.js

          /\      |??????| /??????/   /??????/
     /\  /  \     |  |/  /   /  /
    /  \/    \    |     (   /   ??????\
   /          \   |  |\  \ |  (???)  |
  / __________ \  |__| \__\ \_____/ .io

  execution: local
     script: script.js
     output: -

  scenarios: (100.00%) 1 scenario, 1 max VUs, 40s max duration (incl. graceful stop):
           * default: 1 looping VUs for 10s (gracefulStop: 30s)


running (12.4s), 0/1 VUs, 4 complete and 0 interrupted iterations
default ??? [======================================] 1 VUs  10s

     ??? status was 200

     checks.........................: 100.00% ??? 4        ??? 0
     data_received..................: 9.8 kB  792 B/s
     data_sent......................: 670 B   54 B/s
     http_req_blocked...............: avg=68.21ms  min=1??s   med=1??s      max=272.84ms p(90)=190.99ms p(95)=231.91ms
     http_req_connecting............: avg=6.81ms   min=0s    med=0s       max=27.25ms  p(90)=19.07ms  p(95)=23.16ms
     http_req_duration..............: avg=3.02s    min=3s    med=3.01s    max=3.04s    p(90)=3.03s    p(95)=3.04s
       { expected_response:true }...: avg=3.02s    min=3s    med=3.01s    max=3.04s    p(90)=3.03s    p(95)=3.04s
     http_req_failed................: 0.00%   ??? 0        ??? 4
     http_req_receiving.............: avg=652??s    min=203??s med=700.49??s max=1ms      p(90)=953??s    p(95)=978.49??s
     http_req_sending...............: avg=366.74??s min=122??s med=150.5??s  max=1.04ms   p(90)=776.7??s  p(95)=910.34??s
     http_req_tls_handshaking.......: avg=60.76ms  min=0s    med=0s       max=243.04ms p(90)=170.13ms p(95)=206.58ms
     http_req_waiting...............: avg=3.01s    min=3s    med=3.01s    max=3.04s    p(90)=3.03s    p(95)=3.04s
     http_reqs......................: 4       0.323674/s
     iteration_duration.............: avg=3.08s    min=3s    med=3.03s    max=3.28s    p(90)=3.21s    p(95)=3.25s
     iterations.....................: 4       0.323674/s
     vus............................: 1       min=1      max=1
     vus_max........................: 1       min=1      max=1
```

### Writes using fly-replay

```
k6 run --vus 1 --duration 10s script.js

          /\      |??????| /??????/   /??????/
     /\  /  \     |  |/  /   /  /
    /  \/    \    |     (   /   ??????\
   /          \   |  |\  \ |  (???)  |
  / __________ \  |__| \__\ \_____/ .io

  execution: local
     script: script.js
     output: -

  scenarios: (100.00%) 1 scenario, 1 max VUs, 40s max duration (incl. graceful stop):
           * default: 1 looping VUs for 10s (gracefulStop: 30s)


running (10.4s), 0/1 VUs, 28 complete and 0 interrupted iterations
default ??? [======================================] 1 VUs  10s

     ??? status was 200

     checks.........................: 100.00% ??? 28       ??? 0
     data_received..................: 42 kB   4.0 kB/s
     data_sent......................: 1.5 kB  148 B/s
     http_req_blocked...............: avg=11.98ms  min=0s       med=1??s      max=335.49ms p(90)=2??s      p(95)=2??s
     http_req_connecting............: avg=837.39??s min=0s       med=0s       max=23.44ms  p(90)=0s       p(95)=0s
     http_req_duration..............: avg=358.26ms min=307.24ms med=329.03ms max=620.91ms p(90)=409.06ms p(95)=409.43ms
       { expected_response:true }...: avg=358.26ms min=307.24ms med=329.03ms max=620.91ms p(90)=409.06ms p(95)=409.43ms
     http_req_failed................: 0.00%   ??? 0        ??? 28
     http_req_receiving.............: avg=1.94ms   min=165??s    med=1.68ms   max=7.11ms   p(90)=3.3ms    p(95)=4.84ms
     http_req_sending...............: avg=166.85??s min=88??s     med=132??s    max=674??s    p(90)=215.5??s  p(95)=256.04??s
     http_req_tls_handshaking.......: avg=8.77ms   min=0s       med=0s       max=245.73ms p(90)=0s       p(95)=0s
     http_req_waiting...............: avg=356.15ms min=306.25ms med=326.29ms max=620.41ms p(90)=408.1ms  p(95)=408.34ms
     http_reqs......................: 28      2.697579/s
     iteration_duration.............: avg=370.59ms min=307.8ms  med=329.34ms max=956.84ms p(90)=409.36ms p(95)=409.76ms
     iterations.....................: 28      2.697579/s
     vus............................: 1       min=1      max=1
     vus_max........................: 1       min=1      max=1
```

As you can see, the average request time is still relatively slow when doing a write however it is a clear improvement over using a connection to a single database URL.

### Bonus commands

Use `fly open` as a shortcut to open the app's URL in your browser. If you are using http, Fly will upgrade it to https.

Use `fly logs` to see the log files.

Use `fly status` to get its details:

```
App
  Name     = your-app-name
  Owner    =
  Version  = 1
  Status   = running
  Hostname = your-app-name.fly.dev

Deployment Status
  ID          = a3c2f40e-bed9-4ce1-923a-9d8ad3183a1c
  Version     = v1
  Status      = successful
  Description = Deployment completed successfully
  Instances   = 1 desired, 1 placed, 1 healthy, 0 unhealthy

Instances
ID      	PROCESS	VERSION	REGION	DESIRED	STATUS 	HEALTH CHECKS     	RESTARTS	CREATED
abcdefgh	app    	1     	lhr   	run    	running	2 total, 2 passing	0       	0h10m ago
```

## Run it locally

**Note:** This application is designed to be deployed on Fly's global platform and so its `/read` and `/write` pages won't work as intended. Requests won't be replayed when running locally as there is no need to.

If you _do_ want to try it you will need PHP 8+. You can check the version using `php --version`. And [composer](https://getcomposer.org/). And a local, empty PostgreSQL database.

1. Clone this repo
2. Duplicate `.env.example` naming it `.env`
3. Update the database settings in `.env` to use your local test database connection (such as its username and password)
4. Run `composer install` to install its dependencies
5. Run `php artisan key:generate` to generate a new secret key
6. Run `php artisan migrate`: in addition to the default tables the Laravel demo application creates (see the `database/migrations` folder) we add a simple _items_ table so we can test reading and writing
7. Run `php artisan serve` to run a local development server

You should be able to visit `http://localhost:8000` and see the home page.

## Questions

#### Laravel supports using a separate read and write connection. Wouldn't that help?

You can indeed specify a separate _read_ and _write_ in `config/database.php`.

Be aware this approach does **not** work. It will return an error:

```
'pgsql' => [
    'read' => [
      'url' => env('DATABASE_URL')
    ],
    'write' => [
      'url' => env('DATABASE_URL')
    ],
],
```

However you should be able to make this work by overriding the port in order to use a read replica:

```
'pgsql' => [
    'url' => env('DATABASE_URL'),
    'read' => [
      'port' => 5433
    ],
],
```

This approach makes your config simpler and it avoids the need for the exception handler (as writes won't go to a read replica). However:

1. Writes will be slow when run from a region far away from the primary database, as this approach does not take into account where the application is running
2. Reads will be briefly inconsistent when run from the same region as the primary database, as this approach does not take into account where the primary database is running (it always uses the read replica)

#### When I access the read page, I get a 500 error

Check your logs using `fly logs`. Is anything shown there? Double-check your database is in the same Fly organization as your app. If it's not, your app won't be able to resolve its hostname and you will see an error like:

> SQLSTATE[08006] [7] could not translate host name "top2.nearest.of.app-name-here.internal" to address: Name does not resolv

#### When I access the write page, I get a 500 error

First, check the `/read` page works. If so, it must be able to connect to your database. However if writes are not working, that suggests the primary database is not receiving the replayed requests. Is there a vm in that region (from `fly status`?) Is the primary database in that region?

Also, double-check the app knows both. If you visit the `/read` page you should see both are output to show both the `[env]` value _and_ the header value can both be accessed. It should look something like this:

```
Latency testing

Read 5 item(s) in 20.50ms

JJTGTKUPXe
rsPR1Euc4k
mvE5CrRzzD
FugiESPSmf
6Mo4L6iQrL

FLY_REGION is lhr

PRIMARY_REGION is scl
```