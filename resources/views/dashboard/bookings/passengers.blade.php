@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class='fas fa-users'></i> Passengers</h1>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                        <li class="breadcrumb-item active">Bookings</li>
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
                                <form class='search-form row' id='search-form'>
                                    <div class="col-sm-4">
                                        <label>Search Name</label>
                                        <input type="text" class="form-control mb-1" name="search"
                                               placeholder="Search">
                                    </div>
                                    <div class="col-sm-4">
                                        <label>From</label>
                                        <select class="form-control mb-1" name="from" id='search-from'>
                                        </select>
                                    </div>
                                    <div class="col-sm-4">
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
                                               placeholder="From Date" value='{{ \Carbon\Carbon::today()->format('Y-m-d') }} 00:00:00'>
                                    </div>
                                    <div class="col-sm-3">
                                        <label>To Date</label>
                                        <input type="text" class="form-control mb-1" name="to_date" id='to_date'
                                               placeholder="To Date" value='{{ \Carbon\Carbon::today()->format('Y-m-d') }} 23:59:59'>
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

                            <div class="table-responsive">
                                <table class='table'>
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Name</th>
                                        <th>Vehicle</th>
                                        <th>From</th>
                                        <th>To</th>
                                        <th>Sacco</th>
                                        <th>Passengers</th>
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
@endsection
@push('js')
    <script>
        $(document).ready(function () {
            flatpickr("#from_date, #to_date", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                //defaultDate: new Date(),
            });
            
            var sacco_id = "{{ $sacco != null?$sacco->id:0 }}";
            var sacco = "{{ $sacco != null?$sacco->name:0 }}";

            $('#search-from').select2({
                width: '100%',
                placeholder: 'Select From',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{url("routes/search/places")}}',
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
            $('#search-to').select2({
                width: '100%',
                placeholder: 'Select to',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{url("routes/search/places")}}',
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
            
            $('#search-sacco').select2({
                width: '100%',
                placeholder: 'Select Sacco',
                //dropdownParent: $('#saccoModal'),
                allowClear: sacco_id>0?false:true,
                ajax: {
                    url: '{{url("saccos/search")}}',
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
            if(sacco_id > 0){
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
                    url: "{{ url('bookings/datatable/passengers') }}",
                    data: function (d) {
                        d.search = $('#search-form input[name=search]').val();
                        d.from_date = $('#search-form input[name=from_date]').val();
                        d.to_date = $('#search-form input[name=to_date]').val();
                        d.sacco = $('#search-form select[name=sacco]').val();
                        d.from = $('#search-form select[name=from]').val();
                        d.to = $('#search-form select[name=to]').val();
                        d.status = $('#search-form select[name=status]').val();
                    }
                },

                buttons: [
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn btn-danger btn-sm',
                        title: 'Passengers',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        title: 'Passengers',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-primary btn-sm',
                        title: 'Passengers',
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
                {data: 'queue.vehicle.plate', name: 'queue.vehicle.plate'},
                {data: 'from.name', name: 'from.name'},
                {data: 'to.name', name: 'to.name'},
                {data: 'queue.vehicle.sacco.name', name: 'queue.vehicle.sacco.name', defaultContent: 'N/A'},
                {data: 'passengers', name: 'passengers'},
                {data: 'amount', name: 'amount'},
                {
                    data: 'status',
                    name: 'status',
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
        $('#search-form input[name=search]').keyup(function(){
            clearTimeout(timer);
            timer = setTimeout(function(){
                table.draw();
            }, 1000);
        })

        $('#search-form').on('submit', function (e) {
            e.preventDefault();
            table.draw();
        });
        $('#from_date, #to_date, #search-form select').change(function(){
            table.draw();
        });

            $('.btn-launch-modal').click(function () {
                $('#routeModal .modal-title span').text("New ");
                $('#routeModal input[name=id]').val(0);
                $('#routeModal input[name=name]').val("");
                $('#from').clear();
                $('#to').clear();
                $('#routeModal select[name=status]').val(1);
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
                    url: '{{ url("/route/add") }}',
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
                    if (data) {
                        if (data.errors.name) {
                            $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.name + "<br>");
                        }
                        if (data.errors.from_id) {
                            $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.from_id + "<br>");
                        }
                        if (data.errors.to_id) {
                            $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.to_id + "<br>");
                        }
                        if (data.errors.status) {
                            $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.status + "<br>");
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
