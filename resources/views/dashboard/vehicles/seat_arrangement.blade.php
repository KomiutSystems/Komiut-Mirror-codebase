@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class='fas fa-sliders-h'></i> <b>Seat</b> Arrangement</h1>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                        <li class="breadcrumb-item active"><a href="{{ url('vehicles/seats/settings') }}">Seat Settings</a></li>
                        <li class="breadcrumb-item active">Seat Arrangement</li>
                    </ol>
                    <!--
                    <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal"
                        data-target="#vehicleModal"><i
                        class='fas fa-plus'></i> Add Settings
                    </button>-->
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
                    <!-- Nav tabs -->
                    <ul class="nav nav-pills nav-fill border" id="myTab" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="home-tab" data-toggle="tab" href="#home" role="tab" aria-controls="home" aria-selected="true">
                                <span class='d-block d-md-none'><i class='fas fa-info'></i></span>
                                <span class='d-none d-md-block'>Info</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="profile-tab" data-toggle="tab" href="#profile" role="tab" aria-controls="profile" aria-selected="false">
                                <span class='d-block d-md-none'><i class='fas fa-sliders-h'></i></span>
                                <span class='d-none d-md-block'>Arrangements</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="messages-tab" data-toggle="tab" href="#messages" role="tab" aria-controls="messages" aria-selected="false">
                                <span class='d-block d-md-none'><i class='fas fa-eye'></i></span>
                                <span class='d-none d-md-block'>View</span>
                            </a>
                        </li>
                    </ul>

                    <!-- Tab panes -->
                    <div class="tab-content">
                        <div class="tab-pane active pt-4" id="home" role="tabpanel" aria-labelledby="home-tab">
                            <div class='row'>
                                <div class='col-sm-6 col-md-4 p-2'>
                                    <b>Name:</b><br>
                                    <b class='text-muted'>{{ $seat->name }}</b>
                                </div>
                                <div class='col-sm-6 col-md-4 p-2'>
                                    <b>Seats:</b><br>
                                    <b class='text-muted'>{{ $seat->seats }}</b>
                                </div>
                                <div class='col-sm-6 col-md-4 p-2'>
                                    <b>Rows:</b><br>
                                    <b class='text-muted'>{{ $seat->rows }}</b>
                                </div>
                                <div class='col-sm-6 col-md-4 p-2'>
                                    <b>Columns:</b><br>
                                    <b class='text-muted'>{{ $seat->columns }}</b>
                                </div>
                                <div class='col-sm-6 col-md-4 p-2'>
                                    <b>Status:</b><br>
                                    <b class='text-muted'>{{ $seat->status?'Active':'Inactive' }}</b>
                                </div>
                                <div class='col-sm-6 col-md-4 p-2'>
                                    <b>Date Created:</b><br>
                                    <b class='text-muted'>{{ \Carbon\Carbon::parse($seat->created_at)->diffForHumans() }}</b>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane pt-4" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                            
                            <form class='search-form row alert border d-flex align-items-end' id='search-form'>
                                <div class="col-sm-4">
                                    <label>Search</label>
                                    <input type="text" class="form-control mb-1" name="search"
                                           placeholder="Search">
                                </div>
                                <div class="col-sm-4">
                                    <label>Status</label>
                                    <select name="status" class="form-control mb-1">
                                        <option value="1">Active</option>
                                        <option value="0">Inactive</option>
                                    </select>
                                </div>
                                <div class='col-sm-4 text-right'>
                                    @can('Add Seat Settings')
                                        <span class="btn btn-primary btn-sm btn-launch-modal mb-1" data-toggle="modal"
                                            data-target="#vehicleModal"><i
                                            class='fas fa-plus'></i> Add Settings
                                        </span>
                                    @endcan
                                </div>
                            </form>
                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Name</th>
                                        <th>Column</th>
                                        <th>Row</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th class='text-end notexport'>Action</th>
                                    </tr>
                                    </thead>
                                </table>
                            </div>

                        </div>
                        <div class="tab-pane pt-4" id="messages" role="tabpanel" aria-labelledby="messages-tab">
                            <div class='row'>
                                @for ($i = 1; $i<=$seat->rows; $i++)
                                    @for ($j = 1; $j<=$seat->columns; $j++)
                                        @php
                                            $myseat = $seat->seat_arrangements->where('row', $i)->where('column', $j)->first();
                                            if($myseat != null){
                                                echo "<div class='col'><div class='m-1 alert bg-primary text-center'>".$myseat->name."</div></div>";
                                            }else{
                                                echo "<div class='col'></div>";
                                            }
                                        @endphp
                                    @endfor 
                                    <div class='col-12'></div>
                                @endfor
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
    <div class="modal fade" id="vehicleModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>New </span>
                        Seat Arrangement</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('vehicles/seats/settings/arrangement/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>
                        <input type='hidden' name='seat_id' value='{{ $seat->id }}'>
                        <div class='col-sm-12 form-group'>
                            <label>Name</label>
                            <input type='text' placeholder="Name" name="name" class='form-control' autofocus
                                   required/>
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Column</label>
                            <select name="column" class='form-control' required>
                                @for($i = 1; $i<=$seat->columns; $i++)
                                <option value='{{ $i }}'>{{ $i }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Row</label>
                            <select name="row" class='form-control' required>
                                @for($i = 1; $i<=$seat->rows; $i++)
                                <option value='{{ $i }}'>{{ $i }}</option>
                                @endfor
                            </select>
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
                    url: "{{ url('vehicles/datatable/seats/settings/arrangements') }}/{{ $seat->id }}",
                    data: function (d) {
                        d.search = $('input[name=search]').val();
                        d.status = $('select[name=status]').val();
                    }
                },
                buttons: [
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn btn-danger btn-sm',
                        title: '{{ $seat->name }}_seat_arragements_',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        title: '{{ $seat->name }}_seat_arragements_',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-primary btn-sm',
                        title: '{{ $seat->name }}_seat_arragements_',
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
                    {data: 'column', name: 'column'},
                    {data: 'row', name: 'row'},
                    {
                        data: 'status',
                        name: 'status',
                        render: function (data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<span class="badge badge-primary">Active</span>';
                                default:
                                    return '<span class="badge badge-secondary">Inactive</span>';
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

            var timer;
            $('#search-form input[name=search]').keyup(function(){
                clearTimeout(timer);
                timer = setTimeout(function(){
                    table.draw();
                }, 1000);
            })
            $('#search-form select').change(function(){
                table.draw();
            })
            $('#search-form').on('submit', function (e) {
                e.preventDefault();
                table.draw();
            });

            $('.btn-launch-modal').click(function () {
                $('#vehicleModal .modal-title span').text("New ");
                $('#vehicleModal input[name=id]').val(0);
                $('#vehicleModal input[name=name]').val("");
                $('#vehicleModal select[name=row]').val(1);
                $('#vehicleModal select[name=column]').val();
                $('#vehicleModal input[name=status]').val(1);
            });
            $('#vehicleModal .btnSave').click(function () {
                //$('#vehicleModal form').submit();
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#vehicleModal .feedback').removeClass('d-none');
                $('#vehicleModal .feedback').removeClass('alert-danger');
                $('#vehicleModal .feedback').removeClass('alert-success');
                $('#vehicleModal .feedback').html("<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#vehicleModal form').serialize();
                $.ajax({
                    url: '{{ url("vehicles/seats/settings/arrangement/add") }}',
                    type: 'POST',
                    data: formData
                }).done(function (data) {
                    $('#vehicleModal .feedback').addClass('alert-success');
                    $('#vehicleModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#vehicleModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function (response) {
                    let data = response.responseJSON;
                    $('#vehicleModal .feedback').addClass('alert-danger');
                    $('#vehicleModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.name) {
                            $('#vehicleModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.name + "<br>");
                        }
                        if (data.errors.row) {
                            $('#vehicleModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.row + "<br>");
                        }
                        if (data.errors.column) {
                            $('#vehicleModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.column + "<br>");
                        }
                        if (data.errors.status) {
                            $('#vehicleModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.status + "<br>");
                        }
                    } else if (data.error) {
                        $('#vehicleModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#vehicleModal .feedback').html("<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!");
                    }
                    setTimeout(() => {
                        $('#vehicleModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });

            $(document).on('click', '.table .btn-edit', function () {
                $('#vehicleModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var name = row.find('.name').text();
                var mrow = row.find('.row').text();
                var column = row.find('.column').text();
                var status = row.find('.status').text();

                $('#vehicleModal input[name=id]').val(id);
                $('#vehicleModal input[name=name]').val(name);
                $('#vehicleModal select[name=row]').val(mrow);
                $('#vehicleModal select[name=column]').val(column);
                $('#vehicleModal select[name=status]').val(status);
            });

        });
    </script>
@endpush
