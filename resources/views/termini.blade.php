@extends('layouts.app')

@section('content')

<div class='container-fluid bg-primary'>
    <div class='row d-flex align-items-center header-dark'>
        <div class='col-sm-12 text-center mt-5 pt-5 pb-4 text-white'>
            <h3 class='text-white'><i class='fas fa-map-marker-alt'></i> Termini</h3>
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
                                    <label>Search Name</label>
                                    <input type="text" class="form-control" name="search" placeholder="Search">
                                </div>
                                <div class="col mb-1">
                                    <label>Search Place</label>
                                    <select class="form-control" name="search_place" id='places'>
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
                                            <th>Place</th>
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
            $('#places').select2({
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
                    url: "{{ url('datatable/termini') }}",
                    data: function(d) {
                        d.search = $('.search-form input[name=search]').val();
                        d.search_place = $('.search-form select[name=search_place]').val();
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
                        data: 'name',
                        name: 'name',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'place.name',
                        name: 'place.name',
                        orderable: false,
                        searchable: false
                    },{
                        data: 'action',
                        name: 'action',
                        orderable: false,
                        searchable: false
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
