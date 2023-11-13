@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5><i class='fas fa-sliders-h'></i> <b>Seat</b> Settings</h5>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Seat Settings')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal" data-target="#vehicleModal"><i
                                class='fas fa-plus'></i> Add Settings
                        </button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Seat Settings</li>
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
                        <div class="card-header">
                            <form class='search-form row' id='search-form'>
                                <div class="col-sm-6">
                                    <label>Search</label>
                                    <input type="text" class="form-control mb-1" name="search" placeholder="Search">
                                </div>
                                <div class="col-sm-6">
                                    <label>Status</label>
                                    <select name="status" class="form-control mb-1">
                                        <option value="1">Active</option>
                                        <option value="0">Inactive</option>
                                    </select>
                                </div>
                            </form>
                        </div>
                        <div class='card-body'>

                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Name</th>
                                            <th>Seats</th>
                                            <th>Rows</th>
                                            <th>Columns</th>
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
    <div class="modal fade" id="vehicleModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>New </span>
                        Seat Settings</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('vehicles/seats/settings/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>
                        <div class='col-sm-12 form-group'>
                            <label>Name</label>
                            <input type='text' placeholder="Name" name="name" class='form-control' autofocus
                                required />
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>No. of Seats</label>
                            <input type='number' min='1' placeholder="No of Seats" name="seats"
                                class='form-control' autofocus required />
                        </div>

                        <div class='col-sm-6 form-group'>
                            <label>Rows</label>
                            <input type='number' min='1' placeholder="rows" name="rows" class='form-control'
                                autofocus required />
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Columns</label>
                            <input type='number' placeholder="Columns" name="columns" class='form-control' autofocus
                                required />
                        </div>
                        <div class='col-sm-6 form-group'>
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
        $(document).ready(function() {
            var table = $('.table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('vehicles/datatable/seats/settings') }}",
                    data: function(d) {
                        d.search = $('input[name=search]').val();
                        d.status = $('select[name=status]').val();
                    }
                },
                buttons: [{
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn border btn-sm',
                        title: 'Seats',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn border btn-sm',
                        title: 'Seats',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn border btn-sm',
                        title: 'Seats',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }
                ],
                "lengthMenu": [
                    [20, 100, 250, 500, 1000],
                    [20, 100, 250, 500, 1000]
                ],
                dom: "<'top'B>rt<'bottom'lip><'clear'>",//'lBtrip',
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'name',
                        name: 'name'
                    },
                    {
                        data: 'seats',
                        name: 'seats'
                    },
                    {
                        data: 'rows',
                        name: 'rows'
                    },
                    {
                        data: 'columns',
                        name: 'columns'
                    },
                    {
                        data: 'status',
                        name: 'status',
                        render: function(data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<span class="badge badge-primary">Active</span>';
                                default:
                                    return '<span class="badge badge-secondary">Inactive</span>';
                            }
                        }
                    },
                    {
                        data: 'created_at',
                        name: 'created_at'
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: true,
                        searchable: true
                    },
                ]
            });

            var timer;
            $('#search-form input[name=search]').keyup(function() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    table.draw();
                }, 1000);
            })
            $('#search-form select').change(function() {
                table.draw();
            })
            $('#search-form').on('submit', function(e) {
                e.preventDefault();
                table.draw();
            });

            $('.btn-launch-modal').click(function() {
                $('#vehicleModal .modal-title span').text("New ");
                $('#vehicleModal input[name=id]').val(0);
                $('#vehicleModal input[name=name]').val("");
                $('#vehicleModal input[name=rows]').val("");
                $('#vehicleModal input[name=columns]').val("");
                $('#vehicleModal input[name=seats]').val("");
                $('#vehicleModal input[name=status]').val(1);
            });
            $('#vehicleModal .btnSave').click(function() {
                //$('#vehicleModal form').submit();
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#vehicleModal .feedback').removeClass('d-none');
                $('#vehicleModal .feedback').removeClass('alert-danger');
                $('#vehicleModal .feedback').removeClass('alert-success');
                $('#vehicleModal .feedback').html(
                    "<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#vehicleModal form').serialize();
                $.ajax({
                    url: '{{ url('vehicles/seats/settings/add') }}',
                    type: 'POST',
                    data: formData
                }).done(function(data) {
                    $('#vehicleModal .feedback').addClass('alert-success');
                    $('#vehicleModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " +
                        data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#vehicleModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function(response) {
                    let data = response.responseJSON;
                    $('#vehicleModal .feedback').addClass('alert-danger');
                    $('#vehicleModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.name) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .name + "<br>");
                        }
                        if (data.errors.seats) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .seats + "<br>");
                        }
                        if (data.errors.rows) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .rows + "<br>");
                        }
                        if (data.errors.columns) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .columns + "<br>");
                        }
                        if (data.errors.status) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .status + "<br>");
                        }
                    } else if (data.error) {
                        $('#vehicleModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#vehicleModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!"
                            );
                    }
                    setTimeout(() => {
                        $('#vehicleModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });

            $(document).on('click', '.table .btn-edit', function() {
                $('#vehicleModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var name = row.find('.name').text();
                var seats = row.find('.seats').text();
                var rows = row.find('.rows').text();
                var columns = row.find('.columns').text();
                var status = row.find('.status').text();

                $('#vehicleModal input[name=id]').val(id);
                $('#vehicleModal input[name=name]').val(name);
                $('#vehicleModal input[name=rows]').val(rows);
                $('#vehicleModal input[name=columns]').val(columns);
                $('#vehicleModal input[name=seats]').val(seats);
                $('#vehicleModal input[name=status]').val(status);
            });

        });
    </script>
@endpush
