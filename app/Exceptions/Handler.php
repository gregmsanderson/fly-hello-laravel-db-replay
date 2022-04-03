<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use PDOException;
use Exception;
use Illuminate\Database\QueryException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        Illuminate\Database\QueryException::class
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        /**
         * See https://laravel.com/docs/9.x/errors#reporting-exceptions
         *
         * If you write to a database read-replica it throws an exception so catch that here
         * to not log the request
         */
        $this->reportable(function (Throwable $e) {
            if ($e instanceof QueryException || $e instanceof PDOException) {
                if (str_contains($e->getMessage(), 'SQLSTATE[25006]')) {
                    // ah, this database error was due to trying to write to a read (those are the only failed
                    // requests it makes sense to replay "SQLSTATE[25006]: Read only sql transaction: 7 ERROR ..."
                    // rather than e.g SQLSTATE[08006] or others). Don't want the logs filling up with these so
                    // return false to stop it propagating
                    return false;
                }
            }
        });

        /**
         * See https://laravel.com/docs/9.x/errors#rendering-exceptions
         *
         * If you write to a database read-replica it throws an exception so catch that here
         * to replay the request
         */
        $this->renderable(function (Throwable $e, $request) {
            if ($e instanceof QueryException || $e instanceof PDOException) {
                if (str_contains($e->getMessage(), 'SQLSTATE[25006]')) {
                    // ah, this database error was due to trying to write to a read (those are the only failed
                    // requests it makes sense to replay) e.g "SQLSTATE[25006]: Read only sql transaction: 7 ERROR ..."
                    //logger()->info('Tried to write to a read-replica');

                    $fly_region = config('services.fly.fly_region', false);
                    $primary_region = config('services.fly.primary_region', false);
                    if ($fly_region && $primary_region && $fly_region !== $primary_region) {
                    // this request should be handled by the primary region instead
                        //logger()->info('Replaying request in: ' . $primary_region);

                        // (the user does not see this response)
                        return response('Replaying request in ' . $primary_region, 409, [
                            'fly-replay' => 'region='  . $primary_region,
                            'content-type' => 'text/plain'
                        ]);
                    }
                }
            }
        });
    }
}
