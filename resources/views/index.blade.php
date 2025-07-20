@extends('layouts.app')
@section('content')
    <div class='container-fluid bg' id='home'>
        <div class='row d-flex align-items-center dark'>
            <div class='col-sm-12'>
                <div class='container'>
                    <div class='row'>
                        <div class='col-sm-6 mt-4 pt-4'>
                            @php
                                $host = request()->getHost();
                            @endphp

                            @if(Str::contains($host, 'komiut.com'))
                                <span class='big'>WE ARE READY TO HELP YOU</span><br>
                                <span class='very-big'>COMMUTE WITH EASE</span>
                                <hr style="color: transparent">
                                <p>Steering innovative transportation and mobility – for a smarter future targeting scheduled buses (BRT) and PSV.</p>
                            @elseif(Str::contains($host, '2safiri.co.ke'))
                                <span class='big'>WELCOME TO 2SAFIRI</span><br>
                                <span class='very-big'>COMMUTE WITH  EASE</span>
                                <hr style="color: transparent">
                                <p>2Safiri is steering innovative transportation and mobility – for a smarter future targeting scheduled buses (BRT) and PSV.</p>
                            @else
                                <span class='big'>WE ARE READY TO HELP YOU</span><br>
                                <span class='very-big'>COMMUTE WITH EASE</span>
                                <hr style="color: transparent">
                                <p>Steering innovative transportation and mobility – for a smarter future targeting scheduled buses (BRT) and PSV.</p>
                            @endif

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class='container-fluid' id='services'>
        <div class='row d-flex'>
            <div class='col-sm-12'>
                <div class='container'>
                    <div class='row mb-4 pb-4'>
                        <div class='col-sm-12 text-center pt-4 pb-4 mt-4 mb-4'>
                            <h2>Our <b class='text-primary'>Services</b></h2>
                            <h4 style='font-weight: 400'>What We Do</h4>
                        </div>

                        @foreach ($services as $service)
                            <div class="col-sm-4">
                                <a href='{{ url('services/view/'.$service->id) }}'>
                                    <div class='card border-0 mb-3 bg-white h-100'>
                                        <img src='{{ $service->image != '' ? asset('images/services/' . $service->image) : asset('images/image.png') }}' class="card-img-top"/>
                                        <div class='card-body'>
                                            <h4>{{ $service->name }}</h4>
                                            {{ \Str::words(strip_tags($service->description), 15, '...')}}
                                        </div>
                                    </div>
                                </a>
                            </div>
                        @endforeach

                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
