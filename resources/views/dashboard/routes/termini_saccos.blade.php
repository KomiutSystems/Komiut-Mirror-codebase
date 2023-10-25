@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class="fas fa-map-marker-alt"></i> Saccos <b>Termini</b></h1>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Termini Users')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal" data-target="#routeModal"><i
                                class='fas fa-plus'></i> Add Sacco Terminus
                        </button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Saccos Termini</li>
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
                                <form class='search-form row d-flex align-items-end' id='search-form'>
                                    <div class="col-sm-4">
                                        <label>Search Terminus</label>
                                        <select class="form-control mb-1" name="search-terminus" id='search-terminus'>
                                        </select>
                                    </div>
                                    <div class="col-sm-4">
                                        <label>Search Sacco</label>
                                        <select class="form-control mb-1" name="search-sacco" id='search-sacco'>
                                        </select>
                                    </div>
                                    <div class="col-sm-4">
                                        <label>Search Place</label>
                                        <select class="form-control mb-1" name="search-place" id='search-place'>
                                        </select>
                                    </div>
                                    <!--
                                        <div class="col-sm-4">
                                            <label>Status</label>
                                            <select name="status" class="form-control mb-1">
                                                <option value='1'>Active</option>
                                                <option value='0'>In-Active</option>
                                            </select>
                                        </div>
                                    -->
                                </form>
                            </div>

                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Terminus</th>
                                            <th>Sacco</th>
                                            <th>Place</th>
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
    <div class="modal fade" id="routeModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>New </span>
                        Sacco Terminus</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('routes/termini/saccos/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>

                        <div class='col-sm-12 form-group'>
                            <label>Terminus</label>
                            <select name="terminus" class='form-control' id='terminus'></select>
                        </div>
                        <div class="col-sm-12">
                            <label>Sacco</label>
                            <select name="sacco" class="form-control mb-1" id='sacco'>
                            </select>
                        </div>
                        <!--
                        <div class="col-sm-12">
                            <label>Place</label>
                            <select name="place" class="form-control mb-1" id='place'>
                            </select>
                        </div>-->
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
        $(document).ready(function() {
            $('#search-sacco').select2({
                width: '100%',
                placeholder: 'Select Sacco',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
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
            $('#search-place').select2({
                width: '100%',
                placeholder: 'Select Place',
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
            $('#search-terminus').select2({
                width: '100%',
                placeholder: 'Select Terminus',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('routes/termini/search') }}',
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
            $('#terminus').select2({
                width: '100%',
                placeholder: 'Select Terminus',
                dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('routes/termini/search') }}',
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
            $('#sacco').select2({
                width: '100%',
                placeholder: 'Select Sacco',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
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
            $('#place').select2({
                width: '100%',
                placeholder: 'Select Place',
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

            var table = $('.table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: "{{ url('routes/termini/datatable/saccos') }}",
                    data: function(d) {
                        d.terminus = $('.search-form select[name=search-terminus]').val();
                        d.place = $('.search-form select[name=search-place]').val();
                        d.sacco = $('.search-form select[name=search-sacco]').val();
                    }
                },

                dom: 'lBtrip',
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'terminus.name',
                        name: 'terminus.name'
                    },
                    {
                        data: 'sacco.name',
                        name: 'sacco.name'
                    },
                    {
                        data: 'terminus.place.name',
                        name: 'terminus.place.name'
                    },
                    {
                        data: 'status',
                        name: 'status',
                        render: function(data, type, row) {
                            switch (data) {
                                case 1:
                                    return '<span class="badge bg-primary">Active</span>';
                                default:
                                    return '<span class="badge bg-danger">Inactive</span>';
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
            $('#search-form select[name=search-terminus], #search-form select[name=search-sacco], #search-form select[name=search-place]')
                .change(function() {
                    table.draw();
                });
            $('.btn-launch-modal').click(function() {
                $('#routeModal .modal-title span').text("New ");
                $('#routeModal input[name=id]').val(0);
                $('#routeModal #sacco').val(null).trigger('change');
                $('#routeModal #terminus').val(null).trigger('change');
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
                    url: '{{ url('routes/termini/saccos/add') }}',
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
                        if (data.errors.terminus) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .terminus + "<br>");
                        }
                        if (data.errors.sacco) {
                            $('#routeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .sacco + "<br>");
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
                $('#routeModal #sacco').val(null).trigger('change');
                $('#routeModal #terminus').val(null).trigger('change');
                $('#routeModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var terminus_id = row.find('.terminus_id').text();
                var terminus = row.find('.terminus').text();
                var sacco_id = row.find('.sacco_id').text();
                var sacco = row.find('.sacco').text();
                var status = row.find('.status').text();

                $('#routeModal input[name=id]').val(id);

                var data = {
                    id: terminus_id,
                    text: terminus
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#terminus').append(newOption).trigger('change');
                var data1 = {
                    id: sacco_id,
                    text: sacco
                };
                var newOption1 = new Option(data1.text, data1.id, false, false);
                $('#sacco').append(newOption1).trigger('change');
                $('#routeModal select[name=status]').val(status);
            });

        });
    </script>
@endpush
