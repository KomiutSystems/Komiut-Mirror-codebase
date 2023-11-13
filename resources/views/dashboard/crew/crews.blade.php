@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5><i class='fas fa-users-cog'></i> Crew</h5>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Crews')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal" data-target="#routeModal"><i
                                class='fas fa-plus'></i> Add Crew</button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Crews</li>
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
                                    <label>Sacco</label>
                                    <select class="form-control mb-1" name="sacco" id='search-sacco'>
                                    </select>
                                </div>
                                <div class="col-sm-3">
                                    <label>Status</label>
                                    <select name="status" class="form-control mb-1">
                                        <option value='1'>Active</option>
                                        <option value='0'>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-sm-3">
                                    <label>Date</label>
                                    <input type="text" class="form-control mb-1" id="date" name="date"
                                        placeholder='Date'>
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
                                            <th>Contacts</th>
                                            <th>Badge</th>
                                            <th>ID No.</th>
                                            <th>User</th>
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
    <div class="modal fade" id="routeModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-user-cog'></i> <span>New </span>
                        Crew</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('crews/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>

                        <div class='col-sm-6 form-group'>
                            <label>First Name<span class='text-danger'>*</span></label>
                            <input type='text' name='firstname' class='form-control' placeholder="First Name"/>
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Last Name<span class='text-danger'>*</span></label>
                            <input type='text' name='lastname' class='form-control' placeholder="Last Name"/>
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>ID Number<span class='text-danger'>*</span></label>
                            <input type='text' name='id_number' class='form-control' placeholder="ID Number"/>
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Badge Number<span class='text-danger'>*</span></label>
                            <input type='text' name='badge_number' class='form-control' placeholder="Badge Number"/>
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Phone Number<span class='text-danger'>*</span></label>
                            <input type='text' name='phone' class='form-control' placeholder="Phone Number"/>
                        </div>
                        <div class='col-sm-6 form-group'>
                            <label>Email Address</label>
                            <input type='email' name='email' class='form-control' placeholder="Email Address"/>
                        </div>
                        <div class="col-sm-6">
                            <label>User Account<span class='text-danger'>*</span></label>
                            <select name="user" class="form-control mb-1" id='user'>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label>Status</label>
                            <select name="status" class="form-control mb-1">
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

            flatpickr("#date", {
                enableTime: true,
                dateFormat: "Y-m-d",
                //defaultDate: new Date(),
            });

            var sacco_id = "{{ $sacco != null ? $sacco->id : 0 }}";
            var sacco = "{{ $sacco != null ? $sacco->name : 0 }}";
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
            if (sacco_id > 0) {
                var data = {
                    id: sacco_id,
                    text: sacco
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#search-sacco').append(newOption).trigger('change');
            }

            $('#user').select2({
                width: '100%',
                placeholder: 'Select User',
                dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('users/search/users') }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
                                return {
                                    text: item.firstname + " " + item.lastname + ' (' + item
                                        .email + ')',
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });$('#search-user').select2({
                width: '100%',
                placeholder: 'Select User',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('users/search/users') }}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function(data) {
                        return {
                            results: $.map(data, function(item) {
                                return {
                                    text: item.firstname + " " + item.lastname + ' (' + item
                                        .email + ')',
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });
            var table = $('.table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('datatable/crews') }}",
                    data: function(d) {
                        d.search = $('.search-form input[name=search]').val();
                        d.sacco = $('.search-form select[name=sacco]').val();
                        d.date = $('.search-form input[name=date]').val();
                        d.status = $('.search-form select[name=status]').val();
                    }
                },
                buttons: [{
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn border btn-sm',
                        title: 'queues_' + $('#from_date').val() + '-' + $('#to_date').val(),
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn border btn-sm',
                        title: 'queues_' + $('#from_date').val() + '-' + $('#to_date').val(),
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn border btn-sm',
                        title: 'queues_' + $('#from_date').val() + '-' + $('#to_date').val(),
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
                        data: null,render: function(data, type, row) {
                            return row.firstname + ' ' + row.lastname;
                        },
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: null,render: function(data, type, row) {
                            return row.phone + ' <br><span class="small">' + row.email+"</span>";
                        },
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'badge_number',
                        name: 'badge_number',
                    },
                    {
                        data: 'id_number',
                        name: 'id_number',
                        
                    },
                    {
                        data: null,render: function(data, type, row) {
                            return row.user.firstname+" "+row.user.lastname+"<br><b class='small text-primary'>"+row.user.email + '</b> <br><span class="small">' + row.user.phone+"</span>";
                        },
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'user.sacco.name',
                        name: 'user.sacco.name',
                        orderable: false,
                        searchable: false,
                        defaultContent: 'N/A'
                    },
                    {
                        data: 'status',
                        name: 'name',
                        render: function(data, type, row) {
                            switch (data.status) {
                                case 1:
                                    return '<span class="badge bg-primary">Active</span>';
                                default:
                                    return '<span class="badge bg-secondary">Inactive</span>';
                            }
                        },
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'created_at',
                        name: 'created_at',
                        orderable: false,
                        searchable: false
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

            $('#search-form input[type=text]').keyup(function() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    table.draw();
                }, 1000);
            });

            $('#search-form input[name=date]').change(function() {
                table.draw();
            });
            $('#search-form select').change(function() {
                table.draw();
            });
            $('.btn-launch-modal').click(function() {
                $('#routeModal .modal-title span').text("New ");
                $('#routeModal input[name=id]').val(0);
                $('#routeModal input[name=firstname]').val("");
                $('#routeModal input[name=lastname]').val("");
                $('#routeModal input[name=id_number]').val("");
                $('#routeModal input[name=badge_number]').val("");
                $('#routeModal input[name=phone]').val("");
                $('#routeModal input[name=email]').val("");
                $('#routeModal select[name=status]').val(1);
                $('#user').val(null).trigger('change');
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
                    url: '{{ url('crews/add') }}',
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
                        if (data.errors.id) {
                            $('#routeModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors.id +
                                "<br>");
                        }
                        if (data.errors.firstname) {
                            $('#routeModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .firstname + "<br>");
                        }
                        if (data.errors.lastname) {
                            $('#routeModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .lastname + "<br>");
                        }
                        if (data.errors.badge_number) {
                            $('#routeModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .badge_number + "<br>");
                        }
                        if (data.errors.id_number) {
                            $('#routeModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .id_number + "<br>");
                        }
                        if (data.errors.phone) {
                            $('#routeModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .phone + "<br>");
                        }
                        if (data.errors.user) {
                            $('#routeModal .feedback').append(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .user + "<br>");
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
                $('#user').empty();
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var firstname = row.find('.firstname').text();
                var lastname = row.find('.lastname').text();
                var badge_number = row.find('.badge_number').text();
                var id_number = row.find('.id_number').text();
                var phone = row.find('.phone').text();
                var email = row.find('.email').text();
                var status = row.find('.status').text();
                var amount = row.find('.amount').text();
                var user = row.find('.user').text();
                var user_id = row.find('.user_id').text();

                $('#routeModal input[name=id]').val(id);
                $('#routeModal input[name=firstname]').val(firstname);
                $('#routeModal input[name=lastname]').val(lastname);
                $('#routeModal input[name=badge_number]').val(badge_number);
                $('#routeModal input[name=id_number]').val(id_number);
                $('#routeModal input[name=phone]').val(phone);
                $('#routeModal input[name=email]').val(email);

                var data = {
                    id: user_id,
                    text: user
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#user').append(newOption).trigger('change');

                $('#routeModal select[name=status]').val(status);
            });

        });
    </script>
@endpush
