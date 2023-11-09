@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class='fas fa-user-shield'></i> Saccos</h1>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @if(auth()->user()->can('Add Saccos') && auth()->user()->sacco_id <= 0)
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal"
                        data-target="#saccoModal"><i
                        class='fas fa-plus'></i> Add Sacco
                        </button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Saccos</li>
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
                    <div class="card card-primary card-outline">
                        <div class="card-body box-profile">
                            <div class="card-body">
                                <form class='search-form row' id='search-form'>
                                    <div class="col-sm-6">
                                        <label>Search Name</label>
                                        <input type="text" class="form-control mb-1" name="search"
                                               placeholder="Search">
                                    </div>
                                    <div class="col-sm-6">
                                        <label>Status</label>
                                        <select name="status" class="form-control mb-1">
                                            <option value='1'>Active</option>
                                            <option value='0'>Inactive</option>
                                        </select>
                                    </div>
                                </form>
                            </div>

                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Name</th>
                                        <th>Slogan</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th class='text-end notexport'>Action</th>
                                    </tr>
                                    </thead>
                                </table>
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
    <div class="modal fade" id="saccoModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-user-lock'></i> <span>New </span>
                        Sacco</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('sacco/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>
                        <div class='col-sm-12 form-group'>
                            <label>Sacco Name</label>
                            <input type='text' placeholder="Sacco name" name="name" class='form-control' autofocus
                                   required/>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Slogan</label>
                            <input type='text' placeholder="Sacco Slogan" name="slogan" class='form-control' autofocus
                                   required/>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Phone</label>
                            <input type='text' placeholder="Phone #" name="phone" class='form-control' autofocus
                                   required/>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Status</label>
                            <select name="status" class='form-control'>
                                <option value='1'>Active</option>
                                <option value='0'>Inactive</option>
                            </select>
                        </div>
                        <div class='alert feedback border d-none'>
                            <i class='fas fa-spinner fa-pulse'></i> Saving... Please wait
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><i
                            class='fas fa-times'></i> Close
                    </button>
                    <button type="button" class="btn btn-primary btn-sm btnSave"><i class='fas fa-paper-plane'></i> Save
                        changes
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('js')
    <script>

        $(document).ready(function () {

            var table = $('.table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('datatable/saccos') }}",
                    data: function (d) {
                        d.search = $('.search-form input[name=search]').val();
                        d.status = $('.search-form  select[name=status]').val();
                    }
                },
                buttons: [
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn btn-danger btn-sm',
                        title: 'Saccos',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        title: 'Saccos',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-primary btn-sm',
                        title: 'Saccos',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }
                ],
                "lengthMenu": [ [20, 100, 250, 500, 1000], [20,100, 250, 500, 1000] ],
                dom: 'lBtrip',
                columns: [
                    {data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false},
                    {data: 'name', name: 'name'},
                    {data: 'slogan', name: 'slogan'},
                    {data: 'phone', name: 'phone'},
                    {
                        data: 'status',
                        name: 'status',
                        render: function (data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<span class="badge bg-primary">Active</span>';
                                default:
                                    return '<span class="badge bg-secondary">Inactive</button>';
                            }
                        }
                    },
                    {data: 'created_at', name: 'created_at'},
                    {
                        data: 'action',
                        name: 'action',
                        orderable: true,
                        searchable: true
                    },
                ]
            });
            var timer = null;
            $('#search-form input[name=search]').keyup( function () {
                clearTimeout(timer);
                timer = setTimeout(function(){
                    table.draw();
                }, 1000);

            });
            $('#search-form select[name=status]').change( function () {
                table.draw();
            });

            $('#search-form').on('submit', function (e) {
                e.preventDefault();
                table.draw();
            });

            $('.btn-launch-modal').click(function () {
                $('#saccoModal .modal-title span').text("New ");
                $('#saccoModal input[name=id]').val(0);
                $('#saccoModal input[name=name]').val("");
                $('#saccoModal input[name=slogan]').val("");
                $('#saccoModal input[name=phone]').val("");
                $('#saccoModal input[name=status]').val("");
            });
            $('#saccoModal .btnSave').click(function () {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#saccoModal .feedback').removeClass('d-none');
                $('#saccoModal .feedback').removeClass('alert-danger');
                $('#saccoModal .feedback').removeClass('alert-success');
                $('#saccoModal .feedback').html("<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#saccoModal form').serialize();
                $.ajax({
                    url: '{{ url("sacco/add") }}',
                    type: 'POST',
                    data: formData
                }).done(function (data) {
                    $('#saccoModal .feedback').addClass('alert-success');
                    $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#saccoModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function (response) {
                    let data = response.responseJSON;
                    $('#saccoModal .feedback').addClass('alert-danger');
                    $('#saccoModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.name) {
                            $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.name + "<br>");
                        }
                        if (data.errors.phone) {
                            $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.phone + "<br>");
                        }
                        if (data.errors.slogan) {
                            $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.slogan + "<br>");
                        }
                        if (data.errors.status) {
                            $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.status + "<br>");
                        }
                    } else if (data.error) {
                        $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!");
                    }
                    setTimeout(() => {
                        $('#saccoModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });
            $(document).on('click', '.table .btn-edit', function () {
                $('#saccoModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var name = row.find('.name').text();
                var slogan = row.find('.slogan').text();
                var phone = row.find('.phone').text();
                var status = row.find('.status').text();

                $('#saccoModal input[name=id]').val(id);
                $('#saccoModal input[name=name]').val(name);
                $('#saccoModal input[name=slogan]').val(slogan);
                $('#saccoModal input[name=phone]').val(phone);
                $('#saccoModal input[name=status]').val(status);
            });
        });
    </script>
@endpush
