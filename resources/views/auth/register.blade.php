@extends('layouts.app')

@section('content')
<div class="container-fluid login">
    <div class="row justify-content-center background d-flex align-items-center">
        <div class="col-md-8 col-lg-6 pt-5">
            <div class="card shadow-lg bg-white border-0 mt-5 mb-4">
                <!--<div class="card-header">
                </div>-->

                <div class="card-body p-4">
                    <form method="POST" action="{{ route('register') }}" class="row">
                        <h3><i class="fa-sharp fa-regular fa-user"></i> {{ __('Register') }}</h3><br>
                        <hr>
                        @csrf
                        <div class="col-sm-6 mb-3">
                            <label for="firstname">{{ __('First Name') }}</label>
                            <div >
                                <input id="firstname" type="text" class="form-control @error('firstname') is-invalid @enderror" name="firstname" value="{{ old('firstname') }}" 
                                placeholder='firstname' required autocomplete="name" autofocus>

                                @error('firstname')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>

                        <div class="col-sm-6 mb-3">
                            <label for="lastname">{{ __('Last Name') }}</label>
                            <div>
                                <input id="lastname" type="text" class="form-control @error('lastname') is-invalid @enderror" name="lastname" value="{{ old('lastname') }}" 
                                placeholder='Last Name' required autocomplete="name" autofocus>

                                @error('lastname')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>

                        <div class="col-sm-6 mb-3">
                            <label for="email">{{ __('Email Address') }}</label>

                            <div>
                                <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" 
                                placeholder='Email Address' required autocomplete="email">

                                @error('email')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>
                        
                        <div class="col-sm-6 mb-3">
                            <label for="phone">{{ __('Phone') }}</label>

                            <div>
                                <input id="phone" type="phone" class="form-control @error('phone') is-invalid @enderror" name="phone" value="{{ old('phone') }}" 
                                placeholder='Phone Number' required autocomplete="phone">

                                @error('phone')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>

                        <div class="col-sm-6 mb-3">
                            <label for="dob">{{ __('Date Of Birth') }}</label>

                            <div>
                                <input id="dob" type="date" class="form-control @error('dob') is-invalid @enderror" name="dob" value="{{ old('dob') }}" 
                                placeholder='Date of Birth' required autocomplete="dob">

                                @error('dob')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>

                        <div class="col-sm-6 mb-3">
                            <label for="gender">{{ __('Gender') }}</label>

                            <div>
                                <select id="gender" class="form-control @error('gender') is-invalid @enderror" name="gender" >

                                </select>

                                @error('gender')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>

                        <div class="col-sm-6 mb-3">
                            <label for="password">{{ __('Password') }}</label>

                            <div >
                                <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required 
                                placeholder='Password' autocomplete="new-password">

                                @error('password')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>

                        <div class="col-sm-6 mb-3">
                            <label for="password-confirm">{{ __('Confirm Password') }}</label>

                            <div>
                                <input id="password-confirm" type="password" class="form-control" name="password_confirmation" placeholder='confirm password' required autocomplete="new-password">
                            </div>
                        </div>

                        <div class="col-sm-12">
                            <div class="col-md-6 offset-md-6 text-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-pencil"></i> {{ __('Register') }}
                                </button>
                            </div>
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
        $('#gender').select2({
            placeholder: 'Select Gender',
            allowClear: true,
            ajax: {
                url: '{{url("get/genders")}}',
                dataType: 'json',
                delay: 250,
                processResults: function (data) {
                    return {
                        results: $.map(data, function (item) {
                            return {
                                text: item.name,
                                id: item.id
                            }
                        })
                    };
                },
                cache: true
            }
        });
    });
</script>
@endpush