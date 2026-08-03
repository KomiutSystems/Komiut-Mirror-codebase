@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5><i class='fas fa-user-lock'></i> Route</h5>
                </div><!-- /.col -->
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ url('routes') }}">Routes</a></li>
                        <li class="breadcrumb-item active">View</li>
                    </ol>
                </div><!-- /.col -->
            </div><!-- /.row -->
        </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12 mb-3">
                    <div class="col-sm-12 m-2">
                        <div class="card">
                            <div class="card-header">
                                <div class='row d-flex align-items-center'>
                                    <div class='col-sm-8'>
                                        <h5 class="m-0"><b>{{ $route->name }}</b> (
                                            {{ $route->from->name }}-{{ $route->to->name }})</h5>
                                    </div>
                                    <div class='col-sm-4 text-right'>
                                        <a class='btn btn-primary btn-sm' href='{{ url('routes/view/map/' . $route->id) }}'><i
                                                class='fas fa-map-marker-alt'></i> View Map</a>

                                        @if (auth()->user()->can('Add Routes'))
                                            <button class='btn btn-primary btn-sm btn-launch-modal' data-toggle="modal"
                                                data-target='#placeModal'>Add Stage</button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <form class='search-form row mb-1'>
                                    <div class='col-sm-12'>
                                        <label>Search</label>
                                        <input type='text' name='search' class='form-control' placeholder="Search">
                                    </div>
                                </form>
                                <div class='table-responsive'>
                                    <table class="table align-items-center table-flush vehicles w-100">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Place</th>
                                                <th>Status</th>
                                                <th>Created On</th>
                                                <th class='text-right notexport'>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        </tbody>
                                    </table>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>


            </div>
        </div>
        </div>
    </section>

    <div class="modal fade" id="placeModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
        aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header" style="border-bottom: 1px solid rgb(200,200,200);">
                    <h5 class="modal-title" id="exampleModalLabel"><i class='fas fa-plus'></i> <span>New</span> Stage</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" style="border-bottom: 1px solid rgb(200,200,200);">
                    <!-- Place to show the response message -->
                    <div id="responseMessage" class="alert" style="display: none;"></div>

                    <form action="{{ url('routes/stage/add') }}" method="POST" class="row">
                        @csrf
                        <div class="col-sm-12 form-group">
                            <input type="hidden" name="id" class="form-control" value="0">
                            <input type="hidden" name="route_id" class="form-control" value="{{ $route->id }}">
                            <label>Place Name</label>
                            <select id='place' name="place" class='form-control'></select>
                        </div>
                        <div class="col-sm-12 form-group">
                            <label>Status</label>
                            <select class='form-control' name='status'>
                                <option value='1'>Active</option>
                                <option value='0'>Inactive</option>
                            </select>
                            <div class='alert feedback border d-none'>
                                <i class='fas fa-spinner fa-pulse'></i> Saving... Please wait
                            </div>
                    </form>
                </div>

                <div class="modal-footer pt-2 pb-2">
                    <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary btnSave">Save changes</button>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('js')
    <script>
        $(document).ready(function() {

            var timer = null;

            $('#place').select2({
                width: '100%',
                placeholder: 'Select Place',
                dropdownParent: $('#placeModal'),
                allowClear: true,
                ajax: {
                    url: "{{ url('routes/search/places') }}",
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
                    url: "{{ url('datatable/route/stages') }}",
                    data: function(d) {
                        d.search = $('input[name=search]').val();
                        d.id = "{{ $route->id }}";
                        /*
                        d.from_date = $('input[name=from_date]').val();
                        d.from_time = $('input[name=from_time]').val();
                        d.to_date = $('input[name=to_date]').val();
                        d.to_time = $('input[name=to_time]').val();
                        d.status = $('select[name=status]').val();
                        d.d = $('select[name=d]').val();*/
                    }
                },
                buttons: [{
                        extend: 'csv',
                        text: '<i class="fas fa-file"></i> CSV',
                        className: 'btn border btn-sm',
                        title: '{{ $route->from->name }}-{{ $route->to->name }}_places',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn border btn-sm',
                        title: '{{ $route->from->name }}-{{ $route->to->name }}_places',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }, {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn border btn-sm',
                        title: '{{ $route->from->name }}-{{ $route->to->name }}_places',
                        exportOptions: {
                            columns: ':not(.notexport)'
                        }
                    }
                ],
                "lengthMenu": [
                    [20, 100, 250, 500, 1000],
                    [20, 100, 250, 500, 1000]
                ],
                dom: "<'top'B>rt<'bottom'lip><'clear'>", //'lBtrip',
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'place.name',
                        name: 'place.name'
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
            $('.search-form input[name="search"]').keyup(function() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    table.draw();
                }, 1000);
            });

            $('#placeModal .btnSave').click(function() {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $('#placeModal .feedback').removeClass('d-none');
                $('#placeModal .feedback').removeClass('alert-danger');
                $('#placeModal .feedback').removeClass('alert-success');
                $('#placeModal .feedback').html(
                    "<i class='fas fa-spinner fa-pulse'></i> Saving... Please wait");
                var formData = $('#placeModal form').serialize();
                $.ajax({
                    url: '{{ url('routes/stage/add') }}',
                    type: 'POST',
                    data: formData
                }).done(function(data) {
                    $('#placeModal .feedback').addClass('alert-success');
                    $('#placeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " +
                        data.success);
                    table.draw();
                    setTimeout(() => {
                        $('#placeModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                }).fail(function(response) {
                    let data = response.responseJSON;
                    $('#placeModal .feedback').addClass('alert-danger');
                    $('#placeModal .feedback').html("");
                    if (data.errors) {
                        if (data.errors.route_id) {
                            $('#placeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .route_id + "<br>");
                        }
                        if (data.errors.route_id) {
                            $('#placeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .route_id + "<br>");
                        }
                        if (data.errors.place) {
                            $('#placeModal .feedback').html(
                                "<i class='fas fa-exclamation-circle'></i> " + data.errors
                                .place + "<br>");
                        }
                    } else if (data.error) {
                        $('#placeModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> " + data.error);
                    } else {
                        $('#placeModal .feedback').html(
                            "<i class='fas fa-exclamation-circle'></i> <b>Whoops</b> Something went wrong with the server!"
                            );
                    }
                    setTimeout(() => {
                        $('#placeModal .feedback').addClass('d-none');
                    }, 3000);
                    btn.removeAttr('disabled');
                });
            });

            $('.btn-launch-modal').click(function() {
                $('#placeModal .modal-title span').text("New ");
                $('#placeModal input[name=id]').val(0);
                $('#place').val(null).trigger('change');
                $('#placeModal input[name=status]').val(1);
            });

            $(document).on('click', '.table .btn-edit', function() {
                $('#place').empty();
                $('#placeModal .modal-title span').text("Edit ");
                var row = $(this).closest('tr');
                var id = row.find('.id').text();
                var place = row.find('.place').text();
                var place_id = row.find('.place_id').text();
                var route_id = row.find('.route_id').text();
                var status = row.find('.status').text();

                $('#placeModal input[name=id]').val(id);
                $('#placeModal input[name=route_id]').val(route_id);
                $('#placeModal select[name=status]').val(status);
                var data = {
                    id: place_id,
                    text: place
                };
                var newOption = new Option(data.text, data.id, false, false);
                $('#place').append(newOption).trigger('change');
            });
        });
    </script>
@endpush
