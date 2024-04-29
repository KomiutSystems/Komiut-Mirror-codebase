@extends('layouts.app')

@section('content')

<div class='container-fluid bg-primary'>
    <div class='row d-flex align-items-center header-dark'>
        <div class='col-sm-12 text-center mt-5 pt-5 pb-4 text-white'>
            <h3 class='text-white'><i class='fas fa-map-marker-alt'></i> <span style='font-weight: 300;'>{{ $terminus->name }}</span> | {{ $terminus->place->name }}</h3>
        </div>
    </div>
</div>
<div class='container mt-3'>
            <!-- Small boxes (Stat box) -->
            <div class="row">
                <div class="col-md-12 mb-3">
                    <!-- small box -->
                    <div class="card shadow-sm bg-white">
                        <div class="card-header bg-white">
                            <form class='search-form row d-flex align-items-end' id='search-form'>
                                <div class="col mb-1">
                                    <label>Search</label>
                                    <input type="text" class="form-control" name="search" placeholder="Search">
                                </div>
                                <div class="col mb-1">
                                    <label>From</label>
                                    <select class="form-control" name="search_place" id='from'>
                                    </select>
                                </div>
                                <div class="col mb-1">
                                    <label>To</label>
                                    <select class="form-control" name="search_place" id='to'>
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
                                            <th>Vehicle</th>
                                            <th>From</th>
                                            <th>To</th>
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
        $(document).ready(function() {
            $('#from').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Select Place',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('search/places') }}',
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
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Select Place',
                //dropdownParent: $('#routeModal'),
                allowClear: true,
                ajax: {
                    url: '{{ url('search/places') }}',
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
                    url: "{{ url('termini/datatable/queues/'.$terminus->id) }}",
                    data: function(d) {
                        d.search = $('.search-form input[name=search]').val();
                        d.from = $('#from').val();
                        d.to = $('#to').val();
                    }
                },

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
                        data: 'vehicle.plate',
                        name: 'vehicle.plate'
                    },
                    {
                        data: 'route.from.name',
                        name: 'route.from.name'
                    },
                    {
                        data: 'route.to.name',
                        name: 'route.to.name'
                    },
                    {
                        data: 'created_at',
                        name: 'created_at'
                    },{
                        data: 'action',
                        name: 'action',
                        orderable: true,
                        searchable: true
                    },
                ]
            });
            var timer = null;

            $('#search-form input[type=text]').keyup('submit', function() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    table.draw();
                }, 1000);
            });
            $('#search-form select').change(function() {
                table.draw();
            });
        });
    </script>
@endpush
