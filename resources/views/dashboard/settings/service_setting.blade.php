@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5><i class='fas fa-eye'></i> <b>View</b> Service</h5>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Services Settings')
                        <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal"><i
                                class='fas fa-plus'></i> Edit Service</button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item"><a href="{{ url('settings/services') }}">Service Settings</a></li>
                            <li class="breadcrumb-item active">Service</li>
                        </ol>
                    @endcan
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
                    <div class="card">
                        <div class="card-body">
                            <div class='width-check'></div>
                            <ul class="nav nav-pills nav-fill" id="myTab" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" id="home-tab" data-toggle="tab" href="#home"
                                        role="tab" aria-controls="home" aria-selected="true">
                                        <i class='fas fa-tasks'></i> Details
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="picture-tab" data-toggle="tab" href="#picture" role="tab"
                                        aria-controls="picture" aria-selected="false">
                                        <i class='fas fa-camera'></i> Change Image
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-body">
                            <div class='tab-content'>

                                <div class="tab-pane active" id="home" role="tabpanel" aria-labelledby="home-tab">
                                    <div class='row'>
                                        <div class='col-sm-6 col-md-4'>
                                            <img src='{{ $service->image == ""?asset('images/image.png'):asset('images/services/'.$service->image) }}' class='img-fluid'/>
                                        </div>
                                        <div class='col-sm-6 col-md-8'>
                                            <h5>{{ $service->name }}</h5>
                                            {!! $service->description !!}
                                        </div>
                                    </div>
                                </div>

                                <div class="tab-pane" id="picture" role="tabpanel" aria-labelledby="picture-tab">
                                    <h5>Change Service Picture</h5>
                                    <div class='img-div'>
                                        <input type="file" id="upload" class='d-none' accept="image/png, image/jpeg, image/jpg">
                                        <div class='preview'></div>
                                    </div>
                                    <div class='mt-1 alert'></div>
                                    <div class='p-2 text-center'>
                                        <button class='btn btn-outline-primary btn-upload'><i class="fas fa-cloud-upload-alt"></i> Upload Picture</button>
                                        <button class='btn btn-primary btnSave' disabled><i class="fas fa-save"></i> Save Picture</button>
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

    <!-- Profile Modal -->
    <div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>Edit </span> Service
                    </h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('settings/services/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='{{ $service->id }}'>
                        <div class='col-sm-12 form-group'>
                            <label>Name</label>
                            <input type='text' name='name' class='form-control' placeholder="Name" value='{{ $service->name }}' autofocus>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Description</label>
                            <textarea id='description' name="description" class='form-control' placeholder="Description">{{ $service->description }}</textarea>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Status</label>
                            <select name='status' class='form-control'>
                                <option value="1">Active</option>
                                <option value="0">In-Active</option>
                            </select>
                        </div>
                        <div class='alert feedback border d-none'>
                            <i class='fas fa-spinner fa-pulse'></i> Saving... Please wait
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><i
                            class='fas fa-times'></i> Close</button>
                    <button type="button" class="btn btn-primary btn-sm btnSave"><i class='fas fa-paper-plane'></i> Save
                        changes</button>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('js')
    <script>
        $(document).ready(function() {

            $('#description').summernote({
                placeholder: 'Description',
                tabsize: 1,
                height: 100,
                toolbar: [
                    //['style', ['style']],
                    ['font', ['bold', 'underline', 'clear']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    //['table', ['table']],
                    //['insert', ['link', 'picture', 'video']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ]
            });
            $('#userModal .btnSave').click(function() {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#userModal .feedback').removeClass('d-none');
                $('#userModal .feedback').removeClass('alert-danger');
                $('#userModal .feedback').removeClass('alert-success');
                $('#userModal .feedback').html(
                    "<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#userModal form').serialize();
                $.ajax({
                    url: '{{ url('settings/services/add') }}',
                    type: 'POST',
                    data: formData
                }).done(function(data) {
                    $('#userModal .feedback').addClass('alert-success');
                    $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " +
                        data.success);
                    setTimeout(() => {
                        $('#userModal .feedback').addClass('d-none');
                    }, 3000);
                    location.reload();
                    btn.removeAttr('disabled');
                }).fail(function(response) {
                    let data = response.responseJSON;
                    $('#userModal .feedback').addClass('alert-danger');
                    $('#userModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.name) {
                            $('#userModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .name + "<br>");
                        }
                        if (data.errors.description) {
                            $('#userModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .description + "<br>");
                        }

                        if (data.errors.status) {
                            $('#userModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .status + "<br>");
                        }

                    } else if (data.error) {
                        $('#userModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#userModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!"
                        );
                    }
                    setTimeout(() => {
                        $('#userModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });

            //upload
            let width = $('.width-check').width();
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });
            $uploadCrop = $('.preview').croppie({
                url: '{{ $service->image != '' ? asset('images/services/' . $service->image) : asset('images/image.png') }}',
                enableExif: true,
                viewport: {
                    width: width - 20,
                    height: (width*0.75)-20,
                    //type: 'circle'
                },
                boundary: {
                    width: width,
                    height: width*0.75
                }
            });
            $('.btn-upload').click(function() {
                $('#upload').click();
            });
            $('#upload').on('change', function() {
                var reader = new FileReader();
                reader.onload = function(e) {
                    $uploadCrop.croppie('bind', {
                        url: e.target.result
                    }).then(function() {
                        //console.log('jQuery bind complete');
                        // $('.modal-body .img-div').removeClass('d-none');
                        // $('.modal-body .img-div-preview').addClass('d-none');
                        // $('.modal-body .img-div-preview').addClass('d-none');
                        $('#picture .btnSave').removeAttr('disabled');
                    });
                }
                reader.readAsDataURL(this.files[0]);
            });
            $('#picture .btnSave').on('click', function(ev) {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#picture .alert').removeClass('d-none');
                $('#picture .alert').addClass('border-secondary');
                $('#picture .alert').addClass('text-secondary');
                $('#picture .alert').html(
                    '<i class="fas fa-spinner fa-pulse"></i> <b>Loading!</b> Please wait...');
                $uploadCrop.croppie('result', {
                    type: 'canvas',
                    size: 'viewport'
                }).then(function(resp) {
                    $.ajax({
                        url: "{{ url('settings/services/upload/picture') }}",
                        type: "POST",
                        data: {
                            "id":"{{ $service->id }}",
                            "image": resp
                        },
                        success: function(data) {
                            /*html = '<img src="' + resp + '" />';
                            $("#upload-demo-i").html(html);*/
                            $('#picture .alert').removeClass(
                                'border-secondary');
                            $('#picture .alert').removeClass(
                                'text-secondary');
                            $('#picture .alert').addClass('border-success');
                            $('#picture .alert').addClass('text-success');
                            $('#picture .alert').html(
                                '<i class="far fa-check-circle"></i> <b>Success!</b> Profile Uploaded successfully'
                            );
                            setTimeout(() => {
                                $('#picture .alert').removeClass(
                                    'border-success');
                                $('#picture .alert').removeClass(
                                    'text-success');
                            }, 3000);
                            location.reload();
                        },
                        error: function(data) {
                            //console.log(data);
                            $('#picture .alert').removeClass(
                                'border-secondary');
                            $('#picture .alert').removeClass(
                                'text-secondary');
                            $('#picture .alert').addClass('border-danger');
                            $('#picture .alert').addClass('text-danger');
                            $('#picture .alert').html(
                                '<i class="fas fa-exclamation-circle"></i> <b>Whoops!</b> Something went wrong'
                            );
                            setTimeout(() => {
                                $('#picture .alert').removeClass(
                                    'border-danger');
                                $('#picture .alert').removeClass(
                                    'text-danger');
                                $('#picture .alert').addClass(
                                    'd-none');
                                btn.removeAttr('disabled', 'disabled');
                            }, 3000);
                        }
                    });
                });
            });
        });
    </script>
@endpush
