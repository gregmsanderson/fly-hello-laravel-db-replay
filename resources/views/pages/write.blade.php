@extends('layouts.app')

@section('title', 'Write')

@section('content')
    <div style="paddiing: 20px;">
        <h1>Latency testing</h1>

        <p>Wrote item (<strong>{{ $name }})</strong> in <strong>{{ number_format($time, 2) }}ms</strong></p>

        <p>FLY_REGION is <strong>{{ Config::get('services.fly.fly_region') }}</strong> </p>

        <p>PRIMARY_REGION is <strong>{{ Config::get('services.fly.primary_region') }}</strong> </p>
    </div>
@endsection
