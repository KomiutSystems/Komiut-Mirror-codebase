@extends('layouts.app')
@section('content')
    <div class='container-fluid my-top-services'>
        <div class='row d-flex'>
            <div class='col-sm-12'>
                <div class='container'>
                    <div class='row mb-4 pb-4 mt-5 pt-5 text-white'>
                        <div class='col-sm-12 mb-4 border-left p-3 text-white'>
                            <h2 class='text-white'>{{ $service->name }}</h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class='container'>
        <div class='row '>
            <div class="col-sm-12">
                <div class='card border-0' style='margin-top: -10vh;'>
                    <div class='card-body'>
                        <div class='row'>
                            <div class='col-sm-6 col-md-4'>
                                <img src='{{ $service->image != '' ? asset('images/services/' . $service->image) : asset('images/image.png') }}'
                                    class="img-fluid" />
                            </div>

                            <div class='col-sm-6 col-md-8'>
                                <div class='alert'>
                                {!! $service->description !!}
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
@endsection
