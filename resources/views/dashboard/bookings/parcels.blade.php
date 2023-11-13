@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5><i class='fas fa-archive'></i> Parcels</h5>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Parcels')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal" data-target="#routeModal"><i
                                class='fas fa-plus'></i> Add Parcel
                        </button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Parcels</li>
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
                                <div class="col-sm-3">
                                    <label>Search</label>
                                    <input type="text" class="form-control mb-1" name="search" placeholder="Search">
                                </div>
                                <div class="col-sm-3">
                                    <label>Vehicle</label>
                                    <select class="form-control mb-1" name="vehicle" id='search-vehicle'>
                                    </select>
                                </div>
                                <div class="col-sm-3">
                                    <label>From</label>
                                    <select class="form-control mb-1" name="from" id='search-from'>
                                    </select>
                                </div>
                                <div class="col-sm-3">
                                    <label>To</label>
                                    <select class="form-control mb-1" name="to" id='search-to'>
                                    </select>
                                </div>
                                <div class="col-sm-3">
                                    <label>Sacco</label>
                                    <select class="form-control mb-1" name="sacco" id='search-sacco'>
                                    </select>
                                </div>
                                <div class="col-sm-3">
                                    <label>From Date</label>
                                    <input type="text" class="form-control mb-1" name="from_date" id='from_date'
                                        placeholder="From Date"
                                        value='{{ \Carbon\Carbon::today()->format('Y-m-d') }} 00:00:00'>
                                </div>
                                <div class="col-sm-3">
                                    <label>To Date</label>
                                    <input type="text" class="form-control mb-1" name="to_date" id='to_date'
                                        placeholder="To Date"
                                        value='{{ \Carbon\Carbon::today()->format('Y-m-d') }} 23:59:59'>
                                </div>
                                <div class="col-sm-3">
                                    <label>Status</label>
                                    <select name="status" class="form-control mb-1">
                                        <option value='1'>Active</option>
                                        <option value='0'>In-Active</option>
                                    </select>
                                </div>
                            </form>
                        </div>
                        <div class='card-body'>
                            <div class="table-responsive">
                                <table class='table'>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Name</th>
                                            <th>Vehicle</th>
                                            <th>From</th>
                                            <th>To</th>
                                            <th>Sender</th>
                                            <th>Sender Phone</th>
                                            <th>Recipient</th>
                                            <th>Recipient Phone</th>
                                            <th>Sacco</th>
                                            <th>Amount</th>
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
    <div class="modal fade" id="routeModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>New </span>
                        Queue</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('parcels/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>

                        <div class='col-sm-4 form-group'>
                            <label>Name of Parcel</label>
                            <input type='text' name="name" class='form-control' required placeholder="Name of item" />
                        </div>

                        <div class='col-sm-4 form-group'>
                            <label>Sender Name</label>
                            <input type='text' name="sender_name" class='form-control' required
                                placeholder="Sender Name" />
                        </div>
                        <div class='col-sm-4 form-group'>
                            <label>Sender phone</label>
                            <input type='number' name="sender_phone" class='form-control' required
                                placeholder="Sender phone" />
                        </div>
                        <div class='col-sm-4 form-group'>
                            <label>Sender ID NO</label>
                            <input type='number' name="sender_idno" class='form-control' required
                                placeholder="Sender ID NO" />
                        </div>

                        <div class='col-sm-4 form-group'>
                            <label>Recipient Name</label>
                            <input type='text' name="recipient_name" class='form-control' required
                                placeholder="Recipient Name" />
                        </div>
                        <div class='col-sm-4 form-group'>
                            <label>Recipient phone</label>
                            <input type='number' name="recipient_phone" class='form-control' required
                                placeholder="Recipient phone" />
                        </div>
                        <div class='col-sm-4 form-group'>
                            <label>Recipient ID NO</label>
                            <input type='number' name="recipient_idno" class='form-control' required
                                placeholder="Recipient phone" />
                        </div>
                        <div class="col-sm-4 from-group">
                            <label>From</label>
                            <select name="from" class="form-control mb-1" id='from'>
                            </select>
                        </div>
                        <div class="col-sm-4 form-group">
                            <label>To</label>
                            <select name="to" class="form-control mb-1" id='to'>
                            </select>
                        </div>
                        <div class="col-sm-4 form-group">
                            <label>Amount</label>
                            <input type='number' name="amount" class="form-control mb-1" placeholder="amount"
                                required>
                        </div>
                        <div class="col-sm-4 form-group">
                            <label>Vehicle</label>
                            <select name="vehicle" class="form-control mb-1" id='vehicle'>
                            </select>
                        </div>
                        <div class="col-sm-4 form-group">
                            <label>Status</label>
                            <select name="status" class="form-control mb-1" id='status'>
                                <option value="Pending">Pending</option>
                                <option value="Sent">Sent</option>
                                <option value="Recieved">Recieved</option>
                                <option value="Cancelled">Cancelled</option>
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
            flatpickr("#from_date, #to_date", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                //defaultDate: new Date(),
            });

            var sacco_id = "{{ $sacco != null ? $sacco->id : 0 }}";
            var sacco = "{{ $sacco != null ? $sacco->name : 0 }}";
            $('#search-vehicle').select2({
                width: '100%',
                placeholder: 'Select Vehicle',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('vehicles/search') }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
                                return {
                                    text: item.plate,
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });

            $('#vehicle').select2({
                width: '100%',
                placeholder: 'Select Vehicle',
                dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('vehicles/search') }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
                                return {
                                    text: item.plate,
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });

            $('#from').select2({
                width: '100%',
                placeholder: 'Select From',
                dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('routes/search/places') }}',
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

            $('#search-from').select2({
                width: '100%',
                placeholder: 'Select From',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('routes/search/places') }}',
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
                //dropdownParent: $('#saccoModal'),
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
            $('#to').select2({
                width: '100%',
                placeholder: 'Select to',
                dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('routes/search/places') }}',
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
            $('#search-to').select2({
                width: '100%',
                placeholder: 'Select to',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('routes/search/places') }}',
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
                    url: "{{ url('bookings/datatable/parcels') }}",
                    data: function(d) {
                        d.search = $('#search-form input[name=search]').val();
                        d.vehicle = $('#search-form select[name=vehicle]').val();
                        d.from = $('#search-form select[name=from]').val();
                        d.to = $('#search-form select[name=to]').val();
                        d.sacco = $('#search-form select[name=sacco]').val();
                        d.from_date = $('#search-form  input[name=from_date]').val();
                        d.to_date = $('#search-form input[name=to_date]').val();
                        d.status = $('#search-form select[name=status]').val();
                    }
                },

                buttons: [{
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn border btn-sm',
                        title: 'Parcels',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn border btn-sm',
                        title: 'Parcels',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn border btn-sm',
                        title: 'Parcels',
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
                        data: 'vehicle.plate',
                        name: 'vehicle.plate',
                        defaultContent: "N/A",
                        orderable: false
                    },
                    {
                        data: 'from.name',
                        name: 'from.name',
                        orderable: false
                    },
                    {
                        data: 'to.name',
                        name: 'to.name',
                        orderable: false
                    },
                    {
                        data: 'sender_name',
                        name: 'sender_name'
                    },
                    {
                        data: 'sender_phone',
                        name: 'sender_phone'
                    },
                    {
                        data: 'recipient_name',
                        name: 'recipient_name'
                    },
                    {
                        data: 'recipient_phone',
                        name: 'recipient_phone'
                    },
                    {
                        data: 'vehicle.sacco.name',
                        name: 'vehicle.sacco.name',
                        defaultContent: 'N/A',
                        orderable: false
                    },
                    {
                        data: 'amount',
                        name: 'amount'
                    },
                    {
                        data: 'status',
                        name: 'status',
                        render: function(data, type, row) {
                            switch (data) {
                                case "Pending":
                                    return '<span class="badge bg-secondary">Pending</span>';
                                case "Sent":
                                    return '<span class="badge bg-primary">Sent</span>';
                                case "Recieved":
                                    return '<span class="badge bg-success">Recieved</span>';
                                default:
                                    return '<span class="badge bg-danger">Cancelled</span>';
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
                        orderable: false,
                        searchable: false
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
            $('#search-form select, #from_date, #to_date').change(function() {
                table.draw();
            });

            $('#search-form').on('submit', function(e) {
                e.preventDefault();
                table.draw();
            });

            $('.btn-launch-modal').click(function() {
                $('#routeModal .modal-title span').text("New ");
                $('#routeModal input[name=id]').val(0);
                $('#routeModal input[name=name]').val("");
                $('#from').clear();
                $('#to').clear();
                $('#routeModal select[name=status]').val(1);
            });

            $('#routeModal .btnSave').click(function() {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#routeModal .feedback').removeClass('d-none');
                $('#routeModal .feedback').removeClass('alert-danger');
                $('#routeModal .feedback').removeClass('alert-success');
                $('#routeModal .feedback').html(
                    "<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#routeModal form').serialize();
                $.ajax({
                    url: '{{ url('/bookings/parcels/add') }}',
                    type: 'POST',
                    data: formData
                }).done(function(data) {
                    $('#routeModal .feedback').addClass('alert-success');
                    $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " +
                        data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#routeModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function(response) {
                    let data = response.responseJSON;
                    $('#routeModal .feedback').addClass('alert-danger');
                    $('#routeModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.name) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .name + "<br>");
                        }
                        if (data.errors.sender_name) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .sender_name + "<br>");
                        }
                        if (data.errors.sender_phone) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .sender_phone + "<br>");
                        }
                        if (data.errors.sender_idno) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .sender_idno + "<br>");
                        }
                        if (data.errors.recipient_name) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .recipient_name + "<br>");
                        }
                        if (data.errors.recipient_phone) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .recipient_phone + "<br>");
                        }
                        if (data.errors.recipient_idno) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .recipient_idno + "<br>");
                        }
                        if (data.errors.amount) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .amount + "<br>");
                        }
                        if (data.errors.from_id) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .from_id + "<br>");
                        }
                        if (data.errors.to_id) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .to_id + "<br>");
                        }
                        if (data.errors.status) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .status + "<br>");
                        }
                    } else if (data.error) {
                        $('#routeModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#routeModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!"
                            );
                    }
                    setTimeout(() => {
                        $('#routeModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });

            $(document).on('click', '.table .btn-edit', function() {
                $('#routeModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var from = row.find('td:nth-child(3)').text();
                var to = row.find('td:nth-child(4)').text();
                var id = row.find('.id').text();
                var name = row.find('.name').text();
                var from_id = row.find('.from_id').text();
                var to_id = row.find('.to_id').text();
                var status = row.find('.status').text();


                $('#routeModal input[name=id]').val(id);
                $('#routeModal input[name=name]').val(name);

                var data = {
                    id: from_id,
                    text: from
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#from').append(newOption).trigger('change');

                var data1 = {
                    id: to_id,
                    text: to
                };
                var newOption1 = new Option(data1.text, data1.id, false, false);
                $('#to').append(newOption1).trigger('change');

                $('#routeModal select[name=status]').val(status);
            });

        });
    </script>
@endpush
