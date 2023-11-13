@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5><i class='fas fa-sync'></i> <b>Queue</b> Statuses</h5>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Queue Statuses')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal"
                        data-target="#routeModal"><i
                        class='fas fa-plus'></i> Add Status
                        </button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Queue Statuses</li>
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
                                <form class='search-form row d-flex align-items-end' id='search-form'>
                                    <div class="col-sm-4">
                                        <label>Search Name</label>
                                        <input type="text" class="form-control mb-1" name="search"
                                               placeholder="Search">
                                    </div>
                                    <div class="col-sm-4">
                                        <label>Status</label>
                                        <select name="status" class="form-control mb-1">
                                            <option value=''>All</option>
                                            <option value='Pending'>Pending</option>
                                            <option value='Active'>Active</option>
                                            <option value='Suspended'>Suspended</option>
                                            <option value='Cancelled'>Cancelled</option>
                                            <option value='Completed'>Completed</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-4">
                                        <label>Active</label>
                                        <select name="active" class="form-control mb-1">
                                            <option value='1'>Yes</option>
                                            <option value='0'>No</option>
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
                                        <th>Status</th>
                                        <th>Active</th>
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
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>New </span>
                        Status</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('queues/status/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>

                        <div class='col-sm-12 form-group'>
                            <label>Name</label>
                            <input type='text' placeholder="Name" name="name" class='form-control' autofocus
                                   required/>
                        </div>
                        <div class="col-sm-12">
                            <label>Status</label>
                            <select name="status" class="form-control mb-1">
                                <option value='Pending'>Pending</option>
                                <option value='Active'>Active</option>
                                <option value='Suspended'>Suspended</option>
                                <option value='Cancelled'>Cancelled</option>
                                <option value='Completed'>Completed</option>
                            </select>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Active</label>
                            <select name="active" class='form-control'>
                                <option value='1'>Yes</option>
                                <option value='0'>No</option>
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
                    url: "{{ url('queues/datatable/statuses') }}",
                    data: function (d) {
                        d.search = $('.search-form input[name=search]').val();
                        d.status = $('.search-form select[name=status]').val();
                        d.active = $('.search-form select[name=active]').val();
                    }
                },

                buttons: [
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn border btn-sm',
                        title: 'Queue Statuses',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn border btn-sm',
                        title: 'Queue Statuses',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn border btn-sm',
                        title: 'Queue Statuses',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }
                ],
                "lengthMenu": [ [20, 100, 250, 500, 1000], [20,100, 250, 500, 1000] ],
            dom: "<'top'B>rt<'bottom'lip><'clear'>",//'lBtrip',
            columns: [
                {data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false},
                {data: 'name', name: 'name'},
                {
                    data: 'status',
                    name: 'status',
                    render: function (data, type, row) {
                        switch (data) {
                            case "Pending":
                                return '<span class="text-muted">Pending</span>';
                            case "Active":
                                return '<span class="text-primary">Active</span>';
                            case "Suspended":
                                return '<span class="text-warning">Suspended</span>';
                            case "Completed":
                                return '<span class="text-success">Completed</span>';
                            default:
                                return '<span class="text-danger">Cancelled</span>';
                        }
                    }
                },
                {
                    data: 'active',
                    name: 'active',
                    render: function (data, type, row) {
                        switch (data) {
                            case 1:
                                return '<span class="badge bg-primary">Active</span>';
                            default:
                                return '<span class="badge bg-danger">Inactive</span>';
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

        $('#search-form input[type=text]').keyup('submit', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                table.draw();
            }, 1000);
        });
        $('#search-form select[name=status]').change(function(){
            table.draw();
        });
        $('#search-form select[name=active]').change(function(){
            table.draw();
        });
        $('.btn-launch-modal').click(function () {
            $('#routeModal .modal-title span').text("New ");
            $('#routeModal input[name=id]').val(0);
            $('#routeModal input[name=name]').val("");
            $('#routeModal select[name=status]').val("Pending");
            $('#routeModal select[name=active]').val(1);
        });

        $('#routeModal .btnSave').click(function () {
            var btn = $(this);
            btn.attr('disabled', 'disabled');
            $('#routeModal .feedback').removeClass('d-none');
            $('#routeModal .feedback').removeClass('alert-danger');
            $('#routeModal .feedback').removeClass('alert-success');
            $('#routeModal .feedback').html("<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
            var formData = $('#routeModal form').serialize();
            $.ajax({
                url: '{{ url("queues/status/add") }}',
                type: 'POST',
                data: formData
            }).done(function (data) {
                $('#routeModal .feedback').addClass('alert-success');
                $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.success);
                table.draw();
                setTimeout(() => {
                    $('#routeModal .feedback').addClass('d-none');
                }, 3000);
                btn.removeAttr('disabled');
            }).fail(function (response) {
                let data = response.responseJSON;
                $('#routeModal .feedback').addClass('alert-danger');
                $('#routeModal .feedback').html("");
                if (data.errors) {
                    if (data.errors.name) {
                        $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.name + "<br>");
                    }
                    if (data.errors.status) {
                        $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.status + "<br>");
                    }
                    if (data.errors.active) {
                        $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.active + "<br>");
                    }
                } else if (data.error) {
                    $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.error);
                } else {
                    $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!");
                }
                setTimeout(() => {
                    $('#routeModal .feedback').addClass('d-none');
                }, 3000);
                btn.removeAttr('disabled');
            });
        });

        $(document).on('click', '.table .btn-edit', function () {
            $('#routeModal .modal-title span').text("Edit ");
            var row = $(this).closest('tr');
            var id = row.find('.id').text();
            var name = row.find('.name').text();
            var place = row.find('.place').text();
            var place_id = row.find('.place_id').text();
            var status = row.find('.status').text();
            
            $('#routeModal input[name=id]').val(id);
            $('#routeModal input[name=name]').val(name);
                
            var data = {
                id: place_id,
                text: place
            };                
            var newOption = new Option(data.text, data.id, false, false);
            $('#place').append(newOption).trigger('change');
            $('#routeModal select[name=status]').val(status);
        });

    });
    </script>
@endpush
