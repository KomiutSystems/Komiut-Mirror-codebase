@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class='fas fa-user'></i> <b>My</b> Profile</h1>
                </div><!-- /.col -->
                <div class="col-sm-6 text-end">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                        <li class="breadcrumb-item active">Profile</li>
                    </ol>
                </div><!-- /.col -->
            </div><!-- /.row -->
        </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            <!-- Small boxes (Stat box) -->
            <div class="row">
                <div class="col-md-12 mb-3">

                    <!-- small box -->
                    <div class="card card-primary card-outline">
                        <div class="card-body box-profile">
                            <div class="card-body">
                                <table>
                                    <tr>
                                        <td>
                                            <img src='{{Auth::user()->image != ""?asset("images/profiles/".Auth::user()->image):asset("images/male_avatar.svg")}}' class='img-circle' width='100' height='100'>
                                            
                                        </td>
                                        <td class='p-2'>
                                            <b>{{ Auth::user()->firstname }} {{ Auth::user()->lastname }}</b><br>
                                            <b class='text-muted'>{{ $user->roles[0]->name }}</b><br>
                                            {{ Auth::user()->email }}
                                        </td>
                                    </tr>
                                </table>
                                <div class='pt-2'>
                                    <button class='btn btn-outline-primary btn-sm border-0' data-toggle="modal" data-target="#profilePictureModal">Change Photo</button>
                                </div>
                                <hr>
                                <!-- Nav tabs -->
                                <ul class="nav nav-pills nav-fill border" id="myTab" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" id="home-tab" data-toggle="tab" href="#home" role="tab" aria-controls="home" aria-selected="true">
                                            <span class='d-block d-md-none'><i class='fas fa-user'></i></span>
                                            <span class='d-none d-md-block'>Personal Information</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="profile-tab" data-toggle="tab" href="#profile" role="tab" aria-controls="profile" aria-selected="false">
                                            <span class='d-block d-md-none'><i class='fas fa-edit'></i></span>
                                            <span class='d-none d-md-block'>Edit Profile</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="messages-tab" data-toggle="tab" href="#messages" role="tab" aria-controls="messages" aria-selected="false">
                                            <span class='d-block d-md-none'><i class='fas fa-lock'></i></span>
                                            <span class='d-none d-md-block'>Change Password</span>
                                        </a>
                                    </li>
                                </ul>

                                <!-- Tab panes -->
                                <div class="tab-content">
                                    <div class="tab-pane active pt-4" id="home" role="tabpanel" aria-labelledby="home-tab">
                                        <h5>Personal Information</h5>
                                        <div class='row'>
                                            <div class='col-sm-6 col-md-4 p-2'>
                                                <b>First Name:</b><br>
                                                <b class='text-muted'>{{ Auth::user()->firstname }}</b>
                                            </div>
                                            <div class='col-sm-6 col-md-4 p-2'>
                                                <b>Last Name:</b><br>
                                                <b class='text-muted'>{{ Auth::user()->lastname }}</b>
                                            </div>
                                            <div class='col-sm-6 col-md-4 p-2'>
                                                <b>Email Address:</b><br>
                                                <b class='text-muted'>{{ Auth::user()->email }}</b>
                                            </div>
                                            <div class='col-sm-6 col-md-4 p-2'>
                                                <b>Phone Number:</b><br>
                                                <b class='text-muted'>{{ Auth::user()->phone }}</b>
                                            </div>
                                            <div class='col-sm-6 col-md-4 p-2'>
                                                <b>Gender:</b><br>
                                                <b class='text-muted'>{{ $user->gender->name }}</b>
                                            </div>
                                            <div class='col-sm-6 col-md-4 p-2'>
                                                <b>User Role:</b><br>
                                                <b class='text-muted'>{{ $user->roles[0]->name }}</b>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="tab-pane pt-4" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                                        <h5>Edit Profile</h5>
                                        <form class='row edit-profile' method='POST' action="{{ url('profile/change') }}">
                                            @csrf
                                            <input type='hidden' name='id' value='{{ $user->id }}'/>
                                            <div class='col-sm-6 col-md-4 p-2'>
                                                <b>First Name:</b><br>
                                                <input type='text' name='firstname' class='form-control' value='{{ Auth::user()->firstname }}' placeholder="First Name" required/>
                                            </div>
                                            <div class='col-sm-6 col-md-4 p-2'>
                                                <b>Last Name:</b><br>
                                                <input type='text' name='lastname' class='form-control' value='{{ Auth::user()->lastname }}' placeholder="Last Name" required>
                                            </div>
                                            <div class='col-sm-6 col-md-4 p-2'>
                                                <b>Email Address:</b><br>
                                                <input type='email' name='email' class='form-control' value='{{ Auth::user()->email }}' placeholder='Email Address' required readonly/>
                                            </div>
                                            <div class='col-sm-6 col-md-4 p-2'>
                                                <b>Phone Number:</b><br>
                                                <input type='text' name='phone' class='form-control' value='{{ Auth::user()->phone }}' placeholder="Phone Number" required readonly>
                                            </div>
                                            <div class='col-sm-6 col-md-4 p-2'>
                                                <b>Date of Birth:</b><br>
                                                <input type='date' name='dob' id='dob' class='form-control' value='{{ \Carbon\Carbon::parse($user->dob)->format('Y-m-d') }}' placeholder="D.O.B" required readonly>
                                            </div>
                                            <div class='col-sm-6 col-md-4 p-2'>
                                                <b>Gender:</b><br>
                                                <select id='gender' name="gender" class='form-control'></select>
                                            </div>
                                            <div class='col-sm-12 col-md-12 p-2'>
                                                <b>User Role:</b><br>
                                                <input type='text' name='role' class='form-control' value='{{ $user->roles[0]->name }}' placeholder="Role" required readonly/>
                                            </div>
                                            <div class='col-sm-12 p-2'>
                                                <div class='alert feedback border d-none'>
                                                    <i class='fas fa-spinner fa-pulse'></i> Saving... Please wait
                                                </div>
                                            </div>
                                            <div class='col-sm-12 p-2 text-right'>
                                                <button class='btn btn-primary'>Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="tab-pane pt-4" id="messages" role="tabpanel" aria-labelledby="messages-tab">
                                        <h5>Change Password</h5>
                                        <form method="POST" class='change-password' action="{{ url('profile/change/password') }}">
                                            @csrf
                                            <div class='form-group'>
                                                <label>Current Password</label>
                                                <input type='password' placeholder="Current Password" name="current_password" class='form-control' autofocus required/>
                                            </div>
                                            <div class='form-group'>
                                                <label>New Password</label>
                                                <input type='password' placeholder="New Password" name="new_password" class='form-control' autofocus required/>
                                            </div>
                                            <div class='form-group'>
                                                <label>Confirm New Password</label>
                                                <input type='password' placeholder="Confirm New Password" name="confirm_password" class='form-control' autofocus required/>
                                            </div>
                                            <div class='alert feedback border d-none'>
                                                <i class='fas fa-spinner fa-pulse'></i> Saving... Please wait
                                            </div>
                                            <div class='col-sm-12 p-2 text-right'>
                                                <button class='btn btn-primary'>Update Password</button>
                                            </div>
                                        </form>
                                    </div>
                                   
                                </div>
                                
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ./col -->
            </div>
            <!-- /.row -->
        </div><!-- /.container-fluid -->
    </section>
    <!-- /.content -->

    
    <!-- Change Profile Picture Modal -->
    <div class="modal fade" id="profilePictureModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-edit'></i> Change Profile Picture</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!--
                    <div class='img-div-preview'>
                        <img src='{{Auth::user()->image != ""?asset("images/profiles/".Auth::user()->image):asset("images/male_avatar.svg")}}' class="img-fluid w-100 img-rounded shadow">
                    </div>-->
                    <div class='img-div'>
                        <input type="file" id="upload" class='d-none' accept="image/png, image/jpeg, image/jpg">
                        <div class='preview'></div>
                    </div>
                    <div class='mt-1 alert'></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-primary btn-sm btn-upload"><i class='fas fa-cloud-upload-alt'></i> Upload Photo</button>
                    <button type="button" class="btn btn-primary btn-sm btnSave" disabled='disabled'><i class='fas fa-paper-plane'></i> Update Profile</button>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('js')
    <script>
        $(document).ready(function () {
            flatpickr("#from_date, #to_date", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                //defaultDate: new Date(),
            });
            flatpickr("#dob", {
                enableTime: false,
                dateFormat: "Y-m-d",
                //defaultDate: new Date(),
            });
            $('#gender').select2({
                width: '100%',
                placeholder: 'Select Gender',
                //dropdownParent: $('#modelModal'),
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
            var data = {
                id: "{{ $user->gender->id }}",
                text: "{{ $user->gender->name }}"
            };
            var newOption = new Option(data.text, data.id, false, false);
            $('#gender').append(newOption).trigger('change');

            $('.edit-profile').submit(function (e) {
                e.preventDefault();
                var btn = $(this).find('.btn');
                btn.attr('disabled', 'disabled');
                $('.edit-profile .feedback').removeClass('d-none');
                $('.edit-profile .feedback').removeClass('alert-danger');
                $('.edit-profile .feedback').removeClass('alert-success');
                $('.edit-profile .feedback').html("<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('.edit-profile').serialize();
                $.ajax({
                    url: '{{ url("profile/change") }}',
                    type: 'POST',
                    data: formData
                }).done(function (data) {
                    $('.edit-profile .feedback').addClass('alert-success');
                    $('.edit-profile .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.success);
                    location.reload();
                    setTimeout(() => {
                        $('.edit-profile .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function (response) {
                    let data = response.responseJSON;
                    $('.edit-profile .feedback').addClass('alert-danger');
                    $('.edit-profile .feedback').html("");
                    if (data.errors) {
                        if (data.errors.firstname) {
                            $('.edit-profile .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.firstname + "<br>");
                        }
                        if (data.errors.lastname) {
                            $('.edit-profile .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.lastname + "<br>");
                        }
                        if (data.errors.gender) {
                            $('.edit-profile .feedback').append("<i class='fas fa-exclamation-circle'></i> " + data.errors.gender + "<br>");
                        }
                        if (data.errors.dob) {
                            $('.edit-profile .feedback').append("<i class='fas fa-exclamation-circle'></i> " + data.errors.dob + "<br>");
                        }
                    } else if (data.error) {
                        $('.edit-profile .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('.edit-profile .feedback').html("<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!");
                    }
                    setTimeout(() => {
                        $('.edit-profile .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });
            $('.change-password').submit(function (e) {
                e.preventDefault();
                var btn = $(this).find('.btn');
                btn.attr('disabled', 'disabled');
                $('.change-password .feedback').removeClass('d-none');
                $('.change-password .feedback').removeClass('alert-danger');
                $('.change-password .feedback').removeClass('alert-success');
                $('.change-password .feedback').html("<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('.change-password').serialize();
                $.ajax({
                    url: '{{ url("profile/change/password") }}',
                    type: 'POST',
                    data: formData
                }).done(function (data) {
                    $('.change-password .feedback').addClass('alert-success');
                    $('.change-password .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.success);
                    location.reload();
                    setTimeout(() => {
                        $('.change-password .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function (response) {
                    let data = response.responseJSON;
                    $('.change-password .feedback').addClass('alert-danger');
                    $('.change-password .feedback').html("");
                    if (data.errors) {
                        if (data.errors.current_password) {
                            $('.change-password .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.current_password + "<br>");
                        }
                        if (data.errors.new_password) {
                            $('.change-password .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.new_password + "<br>");
                        }
                    } else if (data.error) {
                        $('.change-password .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('.change-password .feedback').html("<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!");
                    }
                    setTimeout(() => {
                        $('.change-password .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });
            let width = 300;
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });
            $uploadCrop = $('.preview').croppie({
                url: '{{Auth::user()->image != ""?asset("images/profiles/".Auth::user()->image):asset("images/male_avatar.svg")}}',
                enableExif: true,
                viewport: {
                    width: width-20,
                    height: width-20,
                    //type: 'circle'
                },
                boundary: {
                    width: width,
                    height: width
                }
            });
            $('.btn-upload').click(function(){
                $('#upload').click();
            });
            $('#upload').on('change', function () { 
                var reader = new FileReader();
                reader.onload = function (e) {
                    $uploadCrop.croppie('bind', {
                        url: e.target.result
                    }).then(function(){
                        //console.log('jQuery bind complete');
                        // $('.modal-body .img-div').removeClass('d-none');
                        // $('.modal-body .img-div-preview').addClass('d-none');
                        // $('.modal-body .img-div-preview').addClass('d-none');
                        $('#profilePictureModal .btnSave').removeAttr('disabled');
                    });
                }
                reader.readAsDataURL(this.files[0]);
            });
            $('#profilePictureModal .btnSave').on('click', function (ev) {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#profilePictureModal .alert').removeClass('d-none');
                $('#profilePictureModal .alert').addClass('border-secondary');
                $('#profilePictureModal .alert').addClass('text-secondary');
                $('#profilePictureModal .alert').html('<i class="fas fa-spinner fa-pulse"></i> <b>Loading!</b> Please wait...');
                $uploadCrop.croppie('result', {
                    type: 'canvas',
                    size: 'viewport'
                }).then(function (resp) {
                    $.ajax({
                        url: "{{ url('profile/upload/picture') }}",
                        type: "POST",
                        data: {"image":resp},
                        success: function (data) {
                            /*html = '<img src="' + resp + '" />';
                            $("#upload-demo-i").html(html);*/
                            $('#profilePictureModal .alert').removeClass('border-secondary');
                            $('#profilePictureModal .alert').removeClass('text-secondary');
                            $('#profilePictureModal .alert').addClass('border-success');
                            $('#profilePictureModal .alert').addClass('text-success');
                            $('#profilePictureModal .alert').html('<i class="far fa-check-circle"></i> <b>Success!</b> Profile Uploaded successfully');
                            setTimeout(() => {
                                $('#profilePictureModal .alert').removeClass('border-success');
                                $('#profilePictureModal .alert').removeClass('text-success');
                            }, 3000);
                            location.href="{{url('profile')}}";
                        },
                        error: function(data) {
                            console.log(data);
                            $('#profilePictureModal .alert').removeClass('border-secondary');
                            $('#profilePictureModal .alert').removeClass('text-secondary');
                            $('#profilePictureModal .alert').addClass('border-danger');
                            $('#profilePictureModal .alert').addClass('text-danger');
                            $('#profilePictureModal .alert').html('<i class="fas fa-exclamation-circle"></i> <b>Whoops!</b> Something went wrong');
                            setTimeout(() => {
                                $('#profilePictureModal .alert').removeClass('border-danger');
                                $('#profilePictureModal .alert').removeClass('text-danger');
                                $('#profilePictureModal .alert').addClass('d-none');
                                btn.removeAttr('disabled', 'disabled');
                            }, 3000);
                        }
                    });
                });
            });
        });
    </script>
@endpush
