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
                    @can('Add Sacco Vehicles')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal"
                        data-target="#saccoModal"><i
                        class='fas fa-plus'></i> Add Vehicle
                        </button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Sacco Vehicles</li>
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
                                    <div class="col-sm-4">
                                        <label>Search</label>
                                        <input type="text" class="form-control mb-1" name="search"
                                               placeholder="Search">
                                    </div>
                                    <div class="col-sm-4">
                                        <label>Sacco</label>
                                        <select name="sacco" id='search-sacco' class='form-control'></select>
                                    </div>
                                    <div class="col-sm-4">
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
                                        <th>Plate</th>
                                        <th>Till</th>
                                        <th>Merchant</th>
                                        <th>Sacco</th>
                                        <th>Start</th>
                                        <th>End</th>
                                        <th>Status</th>
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
    <div class="modal fade" id="saccoModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-user-plus'></i> <span>New </span>
                        Vehicle</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('saccos/vehicle/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>
                        <div class='col-sm-12 form-group'>
                            <label>Vehicle</label>
                            <select name="vehicle" class='form-control' id='vehicle'>
                            </select>
                        </div>
                        <div class='col-sm-12 form-group'>
                            <label>Sacco Name</label>
                            <select name="sacco" class='form-control' id='sacco'>
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
            var sacco_id = "{{ $sacco != null?$sacco->id:0 }}";
            var sacco = "{{ $sacco != null?$sacco->name:0 }}";
            $('#vehicle').select2({
                width: '100%',
                placeholder: 'Select Vehicle',
                dropdownParent: $('#saccoModal'),
                allowClear: true,
                ajax: {
                    url: '{{url("vehicles/search")}}',
                    dataType: 'json',
                    delay: 250,
                    processResults: function (data) {
                        return {
                            results: $.map(data, function (item) {
                                return {
                                    text: item.plate+' ( '+item.till_number+'|'+item.merchant_short_code+' )',
                                    id: item.id
                                }
                            })
                        };
                    },
                    cache: true
                }
            });
            $('#sacco').select2({
                width: '100%',
                placeholder: 'Select Sacco',
                dropdownParent: $('#saccoModal'),
                allowClear: sacco_id> 0?false:true,
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
                var newOption1 = new Option(data.text, data.id, false, false);
                var newOption = new Option(data.text, data.id, false, false);
                $('#sacco').append(newOption1).trigger('change');
                $('#search-sacco').append(newOption).trigger('change');
            }

            var table = $('.table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('saccos/datatable/vehicles') }}",
                    data: function (d) {
                        d.search = $('.search-form input[name=search]').val();
                        d.sacco = $('.search-form  select[name=sacco]').val();
                        d.status = $('.search-form  select[name=status]').val();
                    }
                },
                dom: 'lBtrip',
                columns: [
                    {data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false},
                    {data: 'vehicle.plate', name: 'vehicle.plate'},
                    {data: 'vehicle.till_number', name: 'vehicle.till_number'},
                    {data: 'vehicle.merchant_short_code', name: 'vehicle.merchant_short_code'},
                    {data: 'sacco.name', name: 'sacco.name'},
                    {data: 'start_date', name: 'start_date'},
                    {data: 'end_date', name: 'end_date'},
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
            $('#search-form select[name=status], #search-form select[name=sacco]').change( function () {
                table.draw();
            });

            $('#search-form').on('submit', function (e) {
                e.preventDefault();
                table.draw();
            });

            $('.btn-launch-modal').click(function () {
                $('#saccoModal .modal-title span').text("New ");
                $('#saccoModal input[name=id]').val(0);
                $('#vehicle').val(null).trigger("change");
                if(sacco_id <= 0){
                    $('#sacco').val(null).trigger("change");
                }
                $('#saccoModal input[name=status]').val(1);
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
                    url: '{{ url("saccos/vehicle/add") }}',
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
                        if (data.errors.id) {
                            $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.id + "<br>");
                        }
                        if (data.errors.vehicle) {
                            $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.vehicle + "<br>");
                        }
                        if (data.errors.sacco) {
                            $('#saccoModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.sacco + "<br>");
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
                var vehicle = row.find('.vehicle').text();
                var vehicle_id = row.find('.vehicle_id').text();
                var sacco = row.find('.sacco').text();
                var sacco_id = row.find('.sacco_id').text();
                var status = row.find('.status').text();

                $('#saccoModal input[name=id]').val(id);
                
                var data = {
                    id: vehicle_id,
                    text: vehicle
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#vehicle').append(newOption).trigger('change');
                
                var data = {
                    id: sacco_id,
                    text: sacco
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#sacco').append(newOption).trigger('change');
                $('#saccoModal input[name=status]').val(status);
            });
        });
    </script>
@endpush
