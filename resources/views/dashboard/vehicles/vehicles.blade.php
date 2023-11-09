@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class='fas fa-bus'></i> Vehicles</h1>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Vehicles')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal" data-target="#vehicleModal"><i
                                class='fas fa-plus'></i> Add Vehicle
                        </button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Vehicles</li>
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
                                    <div class="col-sm-3">
                                        <label>Search</label>
                                        <input type="text" class="form-control mb-1" name="search" placeholder="Search">
                                    </div>
                                    <div class="col-sm-3">
                                        <label>Sacco</label>
                                        <select name="sacco" class="form-control mb-1" id='search-sacco'>
                                        </select>
                                    </div>

                                    <div class="col-sm-3">
                                        <label>Seat Type</label>
                                        <select name="seat" class="form-control mb-1" id='search-seat'>
                                        </select>
                                    </div>
                                    <div class="col-sm-3">
                                        <label>Status</label>
                                        <select name="status" class="form-control mb-1">
                                            <option value="1">Active</option>
                                            <option value="0">Inactive</option>
                                        </select>
                                    </div>
                                </form>
                            </div>

                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Plate</th>
                                            <th>Fleet</th>
                                            <th>Till</th>
                                            <th>Merchant</th>
                                            <th>Seats</th>
                                            <th>Sacco</th>
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
                        Vehicle</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('vehicle/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>
                        <div class='col-sm-6 form-group'>
                            <label>Plate Number</label>
                            <input type='text' placeholder="Number Plate" name="plate" class='form-control' autofocus
                                required />
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Till Number</label>
                            <input type='text' placeholder="Till Number" name="till_number" class='form-control'
                                autofocus required />
                        </div>

                        <div class='col-sm-6 form-group'>
                            <label>Fleet Number</label>
                            <input type='text' placeholder="Fleet Number" name="fleet_no" class='form-control' autofocus
                                required />
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Merchant Short Code</label>
                            <input type='text' placeholder="Merchant Short Code" name="merchant_short_code"
                                class='form-control' autofocus required />
                        </div>
                        <div class="col-sm-12 form-group">
                            <label>Sacco</label>
                            <select name="sacco_id" class="form-control mb-1" id='sacco'>
                            </select>
                        </div>
                        <div class="col-sm-6 form-group">
                            <label>Seating</label>
                            <select name="seat_id" class="form-control mb-1" id='seat'>
                            </select>
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
            var sacco_id = "{{ $sacco != null ? $sacco->id : 0 }}";
            var sacco = "{{ $sacco != null ? $sacco->name : 0 }}";

            $('#sacco').select2({
                width: '100%',
                placeholder: 'Select Sacco',
                dropdownParent: $('#vehicleModal'),
                allowClear: sacco_id > 0 ? false : true,
                ajax: {
                    url: '{{ url('saccos/search') }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
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
            $('#search-sacco').select2({
                width: '100%',
                placeholder: 'Select Sacco',
                //dropdownParent: $('#vehicleModal'),
                allowClear: sacco_id > 0 ? false : true,
                ajax: {
                    url: '{{ url('saccos/search') }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
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
            $('#seat').select2({
                width: '100%',
                placeholder: 'Select Seats',
                dropdownParent: $('#vehicleModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('vehicles/search/seats') }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
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
            $('#search-seat').select2({
                width: '100%',
                placeholder: 'Select Seats',
                //dropdownParent: $('#vehicleModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('vehicles/search/seats') }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
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
            if (sacco_id > 0) {
                var data = {
                    id: sacco_id,
                    text: sacco
                };
                var newOption1 = new Option(data.text, data.id, false, false);
                var newOption = new Option(data.text, data.id, false, false);
                $('#sacco').append(newOption1).trigger('change');
                $('#search-sacco').append(newOption).trigger('change');
            }

            var table = $('.table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('datatable/vehicles') }}",
                    data: function(d) {
                        d.search = $('input[name=search]').val();
                        d.status = $('select[name=status]').val();
                        d.seat = $('#search-form select[name=seat]').val();
                        d.sacco = $('#search-form select[name=sacco]').val();
                    }
                },
                buttons: [
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn btn-danger btn-sm',
                        title: 'Vehicles',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        title: 'Vehicles',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-primary btn-sm',
                        title: 'Vehicles',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }
                ],
                "lengthMenu": [ [20, 100, 250, 500, 1000], [20,100, 250, 500, 1000] ],
                dom: 'lBtrip',
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'plate',
                        name: 'plate'
                    },
                    {
                        data: 'fleet_no',
                        name: 'fleet_no'
                    },
                    {
                        data: 'till_number',
                        name: 'till_number'
                    },
                    {
                        data: 'merchant_short_code',
                        name: 'merchant_short_code'
                    },
                    /*
                    {
                        data: null, 
                        render: function (data, type, row) {
                            return row.user.firstname + ' ' + row.user.lastname;
                        }
                    }, */
                    {
                        data: 'seat.name',
                        name: 'seat.name'
                    },
                    {
                        data: 'sacco.name',
                        name: 'sacco.name',
                        defaultContent: 'N/A'
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
            var timer = null;
            $('#search-form input[name=search]').keyup(function() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    table.draw();
                }, 1000);
            });
            $('#search-form select').change(function() {
                table.draw();
            });
            $('#search-form').on('submit', function(e) {
                e.preventDefault();
                table.draw();
            });

            $('.btn-launch-modal').click(function() {
                $('#vehicleModal .modal-title span').text("New ");
                $('#vehicleModal input[name=id]').val(0);
                $('#vehicleModal input[name=plate]').val("");
                $('#vehicleModal input[name=till_number]').val("");
                $('#vehicleModal input[name=fleet_no]').val("");
                $('#vehicleModal input[name=merchant_short_code]').val("");
                //$('#seat').val(null).trigger("change");
                if (sacco_id <= 0) {
                    $('#sacco').empty();
                }
                $('#seat').empty();
                $('#vehicleModal input[name=status]').val(1);
            });
            $('#vehicleModal .btnSave').click(function() {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#vehicleModal .feedback').removeClass('d-none');
                $('#vehicleModal .feedback').removeClass('alert-danger');
                $('#vehicleModal .feedback').removeClass('alert-success');
                $('#vehicleModal .feedback').html(
                    "<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#vehicleModal form').serialize();
                $.ajax({
                    url: '{{ url('vehicle/add') }}',
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
                        if (data.errors.plate) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .plate + "<br>");
                        }
                        if (data.errors.till_number) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .till_number + "<br>");
                        }
                        if (data.errors.fleet_no) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .fleet_no + "<br>");
                        }
                        if (data.errors.sacco_id) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .sacco_id + "<br>");
                        }
                        if (data.errors.seat_id) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .seat_id + "<br>");
                        }
                        if (data.errors.merchant_short_code) {
                            $('#vehicleModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .merchant_short_code + "<br>");
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
                $('#sacco').empty();
                $('#seat').empty();
                $('#vehicleModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var plate = row.find('.plate').text();
                var fleet_no = row.find('.fleet_no').text();
                var till_number = row.find('.till_number').text();
                var merchant_short_code = row.find('.merchant_short_code').text();
                var sacco_id = row.find('.sacco_id').text();
                var sacco = row.find('.sacco').text();
                var seat_id = row.find('.seat_id').text();
                var seat = row.find('.seat').text();
                var user_id = row.find('.user_id').text();
                var status = row.find('.status').text();

                $('#vehicleModal input[name=id]').val(id);
                $('#vehicleModal input[name=plate]').val(plate);
                $('#vehicleModal input[name=fleet_no]').val(fleet_no);
                $('#vehicleModal input[name=till_number]').val(till_number);
                $('#vehicleModal input[name=merchant_short_code]').val(merchant_short_code);
                $('#vehicleModal input[name=sacco_id]').val(sacco_id);
                $('#vehicleModal input[name=user_id]').val(user_id);
                $('#vehicleModal input[name=status]').val(status);
                if (sacco_id > 0) {
                    var data = {
                        id: sacco_id,
                        text: sacco
                    };
                    var newOption = new Option(data.text, data.id, false, false);
                    $('#sacco').append(newOption).trigger('change');
                }
                if (seat_id > 0) {
                    var data = {
                        id: seat_id,
                        text: seat
                    };
                    var newOption = new Option(data.text, data.id, false, false);
                    $('#seat').append(newOption).trigger('change');
                }
            });

        });
    </script>
@endpush
