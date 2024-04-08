@extends('layouts.app')
@section('content')
    <div class='container-fluid header-bg'>
        <div class='row d-flex align-items-center header-dark'>
            <div class='col-sm-12 text-center mt-5 pt-5 pb-4 text-white'>
                <h3 class='text-white'><i class='fas fa-mobile'></i> PAY ONLINE VIA MPESA</h3>
            </div>
        </div>
    </div>
    <div class='container mt-3'>
        <div class='row justify-content-center'>
            <div class='col-sm-8 col-md-7 col-lg-6'>
                <div class='card shadow-sm'>
                    <div class='card-body'>
                <form method='POST' action="{{ url('pay_online') }}">
                    @csrf
                    <div class='form-group mb-2'>
                        <label>Vehicle Plate</label>
                        <input type='text' class='form-control' placeholder="Vehicle Plate" name='vehicle_plate'>
                    </div>
                    <div class='form-group mb-2'>
                        <label>Till Number</label>
                        <input type='text' class='form-control' placeholder="Till Number" name='till_number'>
                    </div>
                    <div class='form-group mb-2'>
                        <label>Seat</label>
                        <input type='text' class='form-control' placeholder="Seat" name='seat' >
                    </div>
                    <div class='form-group mb-2'>
                        <label>Amount to pay</label>
                        <input type='number' min='1' class='form-control' placeholder="Amount to pay">
                    </div>
                    <div class='form-group mt-3'>
                        <button class='btn btn-primary w-100 mt-2'>Make Payments <i class='fas fa-arrow-right'></i> </button>
                    </div>
                </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
