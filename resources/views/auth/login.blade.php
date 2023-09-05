@extends('layouts.app')

@section('content')
<div class="container-fluid login">
    <div class="row justify-content-center background d-flex align-items-center">
        <div class="col-sm-8 col-md-6 col-lg-4 pt-5">
            <div class="card shadow-lg bg-white border-0 mt-5">

                <div class="card-body p-4">
                    <form method="POST" action="{{ route('login') }}">
                        <h3 class='text-primary'><i class="fa-solid fa-right-to-bracket"></i> Sign In</h3>
                        <p>Let's get you started</p>
                        <hr>
                        @csrf
                        <div class="mb-3 text-start">
                            <label for="email">{{ __('Email Address') }}</label>
                            <div>
                                <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" 
                                placeholder='Email Address' required autocomplete="email" autofocus>

                                @error('email')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3 text-start">
                            <label for="password">{{ __('Password') }}</label>

                            <div class="">
                                <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" 
                                placeholder='password' required autocomplete="current-password">

                                @error('password')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>

                                    <label class="form-check-label" for="remember">
                                        {{ __('Remember Me') }}
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                @if (Route::has('password.request'))
                                    <a class="btn btn-link nav-link text-primary" href="{{ route('password.request') }}">
                                        {{ __('Forgot Password?') }}
                                    </a>
                                @endif
                            </div>
                        </div>

                        <div class="">
                            <button type="submit" class="btn btn-primary w-100">
                                {{ __('Login') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
