@extends('layouts.app')
@section('content')
    <div class='container-fluid' id='home'>
    <!-- Hero Section -->
    <section class="hero d-flex align-items-center text-light  pt-5" style="background: linear-gradient(135deg, #dce3ef, #dfc5f6); min-height: 80vh; ">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 text-center text-lg-start">
                    @php $host = request()->getHost(); @endphp

                    @if(Str::contains($host, 'komiut.com'))
                        <h1 class="display-4 fw-bold">Fleet Management Made Simple</h1>
                        <h2 class="display-2 fw-bold text-gradient">Optimize Your Operations</h2>
                        <p class="lead mt-4">Komiut Fleet Admin gives you full control over vehicle expenses, fuel management, and operational insights, making fleet oversight seamless and efficient.</p>
                        <a href="#services" class="btn btn-primary btn-lg mt-4">Explore Komiut Features</a>

                    @elseif(Str::contains($host, '2safiri.co.ke'))
                        <h1 class="display-4 fw-bold">Streamline Your Fleet Operations</h1>
                        <h2 class="display-2 fw-bold text-gradient">Manage, Monitor, Succeed</h2>
                        <p class="lead mt-4">2Safiri Fleet Admin helps you track vehicle spend, approve fuel transactions, and generate detailed reports—all from one central dashboard.</p>
                        <a href="#services" class="btn btn-primary btn-lg mt-4">Explore 2Safiri Features</a>

                    @else
                        <h1 class="display-4 fw-bold">Manage Your Fleet Finances Effortlessly</h1>
                        <h2 class="display-2 fw-bold text-gradient">Control, Track & Optimize</h2>
                        <p class="lead mt-4">Our Fleet Admin platform gives you real-time control over vehicle expenses, fueling, and payment workflows, helping you optimize fleet operations efficiently.</p>
                        <a href="#services" class="btn btn-primary btn-lg mt-4">Explore Features</a>
                    @endif
                </div>

                <div class="col-lg-6 text-center mt-5 mt-lg-0">
                    <img src="{{ asset('images/services.jpg') }}" alt="Dashboard Illustration" class="img-fluid rounded shadow-lg animate__animated animate__fadeInRight">
                </div>
            </div>
        </div>
    </section>

    <!-- Features / Services Section -->
    <section id="services" class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold">Core <span class="text-primary">Features</span></h2>
                <p class="text-muted fs-5">Tools to Manage Your Fleet & Finances Efficiently</p>
            </div>

            <div class="row g-4">
                @foreach ($services as $service)
                    <div class="col-md-6 col-lg-4">
                        <a href="{{ url('services/view/'.$service->id) }}" class="text-decoration-none">
                            <div class="card h-100 shadow-sm border-0 hover-shadow">
                                <img src="{{ $service->image != '' ? asset('images/services/' . $service->image) : asset('images/image.png') }}" class="card-img-top" alt="{{ $service->name }}">
                                <div class="card-body">
                                    <h5 class="card-title fw-bold text-dark">{{ $service->name }}</h5>
                                    <p class="card-text text-muted">{{ \Str::words(strip_tags($service->description), 20, '...') }}</p>
                                </div>
                                <div class="card-footer bg-transparent border-0">
                                    <button class="btn btn-outline-primary w-100">View Details</button>
                                </div>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <!-- Call to Action Section -->
    <section class="py-5 text-light" style="background: linear-gradient(135deg,  #dce3ef, #dfc5f6);">
        <div class="container text-center">
            <h2 class="fw-bold mb-3">Take Full Control of Your Fleet</h2>
            <p class="fs-5 mb-4">Monitor expenses, approve fuel transactions, generate reports, and streamline operations with one central platform.</p>
            <a href="#contact" class="btn btn-lg btn-primary">Start Managing Today</a>
        </div>
    </section>
    </div>
    <style>
        .hover-shadow:hover {
            transform: translateY(-8px);
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15) !important;
        }
        .text-gradient {
            background: linear-gradient(to right, #f1c360, #8e8454);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>

@endsection
