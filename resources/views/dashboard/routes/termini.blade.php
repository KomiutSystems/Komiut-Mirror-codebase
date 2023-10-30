@extends('layouts.dashboard')

@section('content')
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0"><i class='fas fa-bullhorn'></i> Termini</h1>
                </div><!-- /.col -->
                <div class="col-sm-6 text-right">
                    @can('Add Termini')
                        <button class="btn btn-primary btn-sm btn-launch-modal" data-toggle="modal"
                            data-target="#routeModal"><i
                            class='fas fa-plus'></i> Add Terminus
                        </button>
                    @else
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="{{ url('home') }}">Home</a></li>
                            <li class="breadcrumb-item active">Termini</li>
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
                                        <label>Search Name</label>
                                        <input type="text" class="form-control mb-1" name="search"
                                               placeholder="Search">
                                    </div>
                                    <div class="col-sm-4">
                                        <label>Search Place</label>
                                        <input type="text" class="form-control mb-1" name="search_place"
                                               placeholder="Search">
                                    </div>
                                    <div class="col-sm-4">
                                        <label>Status</label>
                                        <select name="status" class="form-control mb-1">
                                            <option value='1'>Active</option>
                                            <option value='0'>In-Active</option>
                                        </select>
                                    </div>
                                </form>
                            </div>

                            <div class="table-responsive">
                                <table class='table w-100'>
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Name</th>
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
                        Terminus</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="{{ url('routes/terminus/add') }}" class="row">
                        @csrf
                        <input type='hidden' name='id' value='0'>

                        <div class='col-sm-12 form-group'>
                            <label>Name</label>
                            <input type='text' placeholder="Name" name="name" class='form-control' autofocus
                                   required/>
                        </div>
                        <div class="col-sm-12">
                            <label>Place</label>
                            <select name="place" class="form-control mb-1" id='place'>
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
            $('#place').select2({
            width: '100%',
            placeholder: 'Select Place',
            dropdownParent: $('#routeModal'),
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

        var table = $('.table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: "{{ url('routes/datatable/termini') }}",
                data: function (d) {
                    d.search = $('.search-form input[name=search]').val();
                    d.search_place = $('.search-form input[name=search_place]').val();
                    d.status = $('.search-form select[name=status]').val();
                }
            },

            dom: 'lBtrip',
            columns: [
                {data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false},
                {data: 'name', name: 'name'},
                {data: 'place.name', name: 'place.name'},
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

        $('#search-form input[type=text]').keyup('submit', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                table.draw();
            }, 1000);
        });
        $('#search-form select[name=status]').change(function(){
            table.draw();
        });
        $('.btn-launch-modal').click(function () {
            $('#routeModal .modal-title span').text("New ");
            $('#routeModal input[name=id]').val(0);
            $('#routeModal input[name=name]').val("");
            $('#place').val(null).trigger('change');
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
                url: '{{ url("routes/terminus/add") }}',
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
                    if (data.errors.id) {
                        $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.id + "<br>");
                    }
                    if (data.errors.name) {
                        $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.name + "<br>");
                    }
                    if (data.errors.place) {
                        $('#routeModal .feedback').html("<i class='fas fa-exclamation-circle'></i> " + data.errors.place + "<br>");
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
