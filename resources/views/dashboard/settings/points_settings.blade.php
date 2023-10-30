@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class='fas fa-star'></i> Points <b>Settings</b></h1>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Point Settings')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal" data-target="#userModal"><i
                                class='fas fa-plus'></i> Add Settings</button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Points Settings</li>
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
                            <form id='search-form' class='row mb-2'>
                                <div class='col-sm-4 mb-2'>
                                    <label>Date</label>
                                    <input name='date' class='form-control' placeholder="Date" id='date' />
                                </div>
                                <div class='col-sm-4 mb-2'>
                                    <label>Sacco</label>
                                    <select name='sacco' id='search-sacco' class='form-control'></select>
                                </div>
                                <div class='col-sm-4 mb-2'>
                                    <label>Role</label>
                                    <select name='role' id='search-role' class='form-control'></select>
                                </div>
                                <div class='col-sm-4 mb-2'>
                                    <label>Points on</label>
                                    <select name="points_on" class='form-control' id='points_on'>
                                        <option value='transactions'>transactions</option>
                                        <option value='bookings'>bookings</option>
                                        <option value='queues'>queues</option>
                                    </select>
                                </div>
                                <div class='col-sm-4 mb-2'>
                                    <label>Points by</label>
                                    <select name="points_by" class='form-control' id='points_by'>
                                        <option value='by amount'>By Amount</option>
                                        <option value='by items'>By Items</option>
                                    </select>
                                </div>
                                <div class='col-sm-4 mb-2'>
                                    <label>Status</label>
                                    <select name='status' class='form-control' id='status'>
                                        <option value="1">Active</option>
                                        <option value="0">In-Active</option>
                                    </select>
                                </div>
                            </form>
                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Sacco</th>
                                            <th>Amount</th>
                                            <th>Items</th>
                                            <th>Points on</th>
                                            <th>Points By</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Start Date</th>
                                            <th>Date</th>
                                            <th class='text-end'>Action</th>
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
    <div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>New </span> Point
                        Settings</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('settings/points/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>
                        <div class='col-sm-6 form-group'>
                            <label>Value of point</label>
                            <input type='number' name="value" class='form-control' placeholder="Value of Point"
                                required />
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Points by</label>
                            <select name="points_by" class='form-control'>
                                <option value='by amount'>By Amount</option>
                                <option value='by items'>By Items</option>
                            </select>
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Points On</label>
                            <select name="points_on" class='form-control'>
                                <option value='transactions'>transactions</option>
                                <option value='bookings'>bookings</option>
                                <option value='queues'>queues</option>
                            </select>
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Sacco</label>
                            <select name='sacco' class='form-control' id='sacco'>
                            </select>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Role</label>
                            <select name='role' class='form-control' id='role'>
                            </select>
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Start Date</label>
                            <input type='text' id='start_date' name="start_date" class='form-control'
                                placeholder="Start Date" required />
                        </div>
                        <div class='col-sm-6 form-group'>
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
            flatpickr("#date", {
                enableTime: false,
                altInput: true,
                altFormat: "F j, Y",
                dateFormat: "Y-m-d",
                //dateFormat: "Y-m-d H:i",
                mode: "range",
                //defaultDate: new Date(),
                defaultDate: [new Date(), new Date()]
            });
            flatpickr("#start_date", {
                enableTime: false,
                altInput: true,
                altFormat: "F j, Y",
                dateFormat: "Y-m-d",
                defaultDate: new Date(),
            });
            var sacco_id = "{{ $sacco != null ? $sacco->id : 0 }}";
            var sacco = "{{ $sacco != null ? $sacco->name : 0 }}";
            $('#points_on, #points_by, #status').select2({
                width: '100%',
            });
            $('#sacco').select2({
                width: '100%',
                placeholder: 'Select Sacco',
                dropdownParent: $('#userModal'),
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
                //dropdownParent: $('#userModal'),
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
            $('#role').select2({
                width: '100%',
                placeholder: 'Select Role',
                dropdownParent: $('#userModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('dashboard/search/roles') }}',
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
            $('#search-role').select2({
                width: '100%',
                placeholder: 'Select Role',
                allowClear: true,
                ajax: {
                    url: '{{ url('dashboard/search/roles') }}',
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
                var newOption = new Option(data.text, data.id, false, false);
                $('#search-sacco').append(newOption).trigger('change');
            }

            var table = $('.table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('settings/datatable/points') }}",
                    data: function(d) {
                        d.date = $('#search-form input[name=date]').val();
                        d.sacco = $('#search-form select[name=sacco]').val();
                        d.role = $('#search-form select[name=role]').val();
                        d.points_on = $('#search-form select[name=points_on]').val();
                        d.points_by = $('#search-form select[name=points_by]').val();
                        d.status = $('#search-form select[name=status]').val();
                    }
                },
                dom: 'lBtrip', //'lfBtrip'
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'sacco.name',
                        name: 'sacco.name',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'amount',
                        name: 'amount',
                    },
                    {
                        data: 'items',
                        name: 'items',
                    },
                    {
                        data: 'points_on',
                        name: 'points_on',
                    },
                    {
                        data: 'points_type',
                        name: 'points_type',
                    },
                    {
                        data: 'role.name',
                        name: 'role.name',
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'status',
                        name: 'status',
                        render: function(data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<span class="badge bg-primary">Active</span>';
                                default:
                                    return '<span class="badge bg-secondary">Inactive</button>';
                            }
                        }
                    },
                    {
                        data: 'start_date',
                        name: 'start_date'
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
            $('#search-sacco, #points_on, #status, #points_by, #search-role, #date').change(function() {
                table.draw();
            });

            $('#search-form input[name=search]').keyup(function() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    table.draw();
                }, 1000)
            });
            $('.btn-launch-modal').click(function() {
                $('#userModal .modal-title span').text("New ");
                $('#userModal input[name=id]').val(0);
                $('#sacco').val(null).trigger('change');
                $('#role').val(null).trigger('change');
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
                    url: '{{ url('settings/points/add') }}',
                    type: 'POST',
                    data: formData
                }).done(function(data) {
                    $('#userModal .feedback').addClass('alert-success');
                    $('#userModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " +
                        data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#userModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function(response) {
                    let data = response.responseJSON;
                    $('#userModal .feedback').addClass('alert-danger');
                    $('#userModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.sacco) {
                            $('#userModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .sacco + "<br>");
                        }
                        if (data.errors.value) {
                            $('#userModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .value + "<br>");
                        }

                        if (data.errors.points_by) {
                            $('#userModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .points_by + "<br>");
                        }

                        if (data.errors.points_on) {
                            $('#userModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .points_on + "<br>");
                        }
                        if (data.errors.role) {
                            $('#userModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .role + "<br>");
                        }

                        if (data.errors.start_date) {
                            $('#userModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .start_date + "<br>");
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
            $(document).on('click', '.table .btn-edit', function() {
                $('#userModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var sacco = row.find('.sacco').text();
                var sacco_id = row.find('.sacco_id').text();
                var role = row.find('.role').text();
                var role_id = row.find('.role_id').text();
                var points_on = row.find('.points_on').text();
                var points_type = row.find('.points_type').text();
                var amount = row.find('.amount').text();
                var items = row.find('.items').text();
                //var payment_mode = row.find('.payment_mode').text();
                var status = row.find('.status').text();

                $('#userModal input[name=id]').val(id);
                if (sacco_id > 0) {
                    var data = {
                        id: sacco_id,
                        text: sacco
                    };
                    var newOption = new Option(data.text, data.id, false, false);
                    $('#sacco').append(newOption).trigger('change');
                }
                if (role_id > 0) {
                    var data = {
                        id: role_id,
                        text: role
                    };
                    var newOption = new Option(data.text, data.id, false, false);
                    $('#role').append(newOption).trigger('change');
                }
                $('#userModal input[name=value]').val(amount > 0 ? amount : items);
                $('#userModal select[name=points_by]').val(points_type);
                $('#userModal select[name=points_on]').val(points_on);
                $('#userModal select[name=status]').val(status);
            });
        });
    </script>
@endpush
