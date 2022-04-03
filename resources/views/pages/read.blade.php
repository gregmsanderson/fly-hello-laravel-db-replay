@extends('layouts.app')

@section('title', 'Read')

@section('content')
    <div style="paddiing: 20px;">
        <h1>Latency testing</h1>

        <p>Read <strong>{{ count($items) }}</strong> item(s) in
            <strong>{{ number_format($time, 2) }}ms</strong>
        </p>

        <ul>
            @foreach ($items as $item)
                <li>{{ $item->name }}</li>
            @endforeach
        </ul>

        <p>FLY_REGION is <strong>{{ Config::get('services.fly.fly_region') }}</strong> </p>

        <p>PRIMARY_REGION is <strong>{{ Config::get('services.fly.primary_region') }}</strong> </p>
    </div>
@endsection
