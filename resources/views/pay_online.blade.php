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
                <form method='POST' action="{{ url('pay_online') }}" id='payForm'>
                    @csrf
                    <input type='hidden' name='seat_id' value="{{ request('seat') != '' ? request('seat') : old('seat_id') }}">
                    <input type='hidden' name='vehicle_id' value="{{ $vehicle->id }}">
                    <input type='hidden' name='user_id' value="{{ auth()->user() != null?auth()->user()->id:'' }}">
                    <div class='form-group mb-2'>
                        <label>Vehicle Plate</label>
                        <input type='text' class='form-control' placeholder="Vehicle Plate" name='vehicle_plate' value='{{ $vehicle->plate }}' readonly>
                    </div>
                    <div class='form-group mb-2'>
                        <label>Till Number</label>
                        <input type='text' class='form-control' placeholder="Till Number" name='till_number' value='{{ request('till_number') != '' ? request('till_number') : old('till_number') }}' readonly>
                    </div>
                    @if($seat != null)
                    <div class='form-group mb-2'>
                        <label>Seat</label>
                        <input type='text' class='form-control' placeholder="Seat" name='seat' value='{{ $seat->name}}' readonly>
                    </div>
                    @endif
                    <div class='form-group mb-2'>
                        <label>MPESA Phone Number</label>
                        <input type='text' class='form-control' placeholder="Phone Number" name='phone' autofocus>
                    </div>
                    <div class='form-group mb-2'>
                        <label>Amount to pay</label>
                        <input type='number' min='1' class='form-control' name='amount' placeholder="Amount to pay">
                    </div>
                    <div class='form-group'>
                        <div class='alert border feedback mb-2 text-center d-none'>
                            <i class='fas fa-spinner fa-pulse'></i> Please wait
                        </div>
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

@push('js')
<script>
    $(document).ready(function(){
        $('#payForm').submit(function(e){
            e.preventDefault();
            $('.feedback').removeClass('d-none');
            $('.feedback').removeClass('alert-danger');
            $('.feedback').removeClass('alert-success');
            $('.feedback').html("<i class='fas fa-spinner fa-pulse'></i> Please wait...");
            var formData = $(this).serialize();
                $.ajax({
                    url: '{{ url('api/qrcode/stk/push') }}',
                    type: 'POST',
                    data: formData
                }).done(function(data) {
                    if(data.ResponseCode == 0){
                    $('.feedback').addClass('alert-success');
                    $('.feedback').html("<i class='fas fa-check-circle'></i> " +
                        data.CustomerMessage);
                    /*setTimeout(() => {
                        $('.feedback').addClass('d-none');
                    }, 3000);*/
                    //btn.removeAttr('disabled');
                    }else{
                        $('.feedback').addClass('alert-danger');
                    $('.feedback').html("<i class='fas fa-exclamation-circle'></i> " +
                        data.errorMessage);
                    /*setTimeout(() => {
                        $('.feedback').addClass('d-none');
                    }, 3000);*/
                    }
                }).fail(function(response) {
                    let data = response.responseJSON;
                    $('.feedback').addClass('alert-danger');
                    $('.feedback').html("");
                    if (data.errors) {
                        if (data.errors.vehicle_id) {
                            $('.feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .vehicle_id + "<br>");
                        }
                        if (data.errors.phone) {
                            $('.feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .phone + "<br>");
                        }
                        if (data.errors.amount) {
                            $('.feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .amount + "<br>");
                        }
                        if (data.errors.seat_id) {
                            $('.feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .seat_id + "<br>");
                        }
                    } else if (data.error) {
                        $('.feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('.feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!"
                        );
                    }
                    setTimeout(() => {
                        $('.feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
        });
    });
</script>

@endpush
