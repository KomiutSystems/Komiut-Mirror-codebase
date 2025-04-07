@extends('layouts.status')

@section('content')
    <div class="container-fluid">
        <div class="row justify-content-center background d-flex align-items-center">
            <div class="col-md-8 col-md-6 col-lg-5 mt-5">
                <h4 class='big bold text-white text-center'>Account Status</h4>
                <div class="card border bg-white border-0 mb-4 shadow">

                    <div class="card-body p-4">
                        <div class='ps-4 pe-4 text-center'>
                            <img src='{{ asset('images/logos/colored-name.png') }}' class="img-fluid p-4" style="max-width: 200px"/><br>
                            @php
                            $status = "Inactive";
                            $class = 'text-danger';
                            if(auth()->user()->status == 0){
                                $status = "Inactive";
                                $class = "text-danger";
                            }
                            @endphp
                            <i class='fas {{ $status=='Pending'?'fa-history':'fa-exclamation-triangle' }} fa-3x {{ $class }}'></i><br>
                            <h4 style='font-weight:bold;'>Your account is <span class="{{ $class }}">{{ $status }}<span></h4>
                            Your account is currently inactive. Please contact your administrator for assistance
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
