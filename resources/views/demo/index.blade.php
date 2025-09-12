<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demo Scan</title>

    {{-- Bootstrap CSS --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    {{-- DataTables + Bootstrap 5 CSS --}}
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
</head>

<body>
    <div class="container my-5">
        <div class="d-flex w-100 justify-content-between">
            <h3>Demo Table</h3>
            <form action="{{ route('demo.upload') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="file" name="file">
                <label>
                    <input type="checkbox" name="use_ocr" value="1"> Use OCR
                </label>
                <button type="submit">Upload</button>
            </form>
        </div>

        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
                <ul class="mb-0">
                    @foreach (session('success') as $section => $content)
                        <li>
                            <strong>{{ ucfirst($section) }}:</strong>
                            <pre class="mb-0" style="white-space: pre-wrap;">{{ $content }}</pre>
                        </li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <div class="card p-2">
            <table id="datatable" class="display responsive nowrap" style="width:100%">
                <thead>
                    <tr>
                        <th></th>
                        <th>No</th>
                        <th>Name</th>
                        <th>Uploaded At</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>

    {{-- jQuery --}}
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    {{-- Bootstrap JS --}}
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    {{-- DataTables + Bootstrap 5 JS --}}
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

    <script>
        const assetBaseUrl = "{{ asset('') }}";

        function format(d) {
            let details = JSON.parse(d.details);
            let html = '<table class="table table-bordered">';
            for (let key in details) {
                html += `<tr>
                    <th style="width:200px">${key}</th>`;

                if (key === 'Photo Profile') {
                    html +=
                        `<td><img src="${assetBaseUrl}${details[key]}" alt="FDW Photo" style="max-width:200px; height:auto; border:1px solid #ddd; border-radius:4px;"/></td>`;
                } else {
                    html += `<td>${details[key]}</td>`;
                }

                html += '</tr>';
            }
            html += '</table>';
            return html;
        }


        $(function() {
            let table = $('#datatable').DataTable({
                processing: true,
                serverSide: true,
                ajax: '{{ route('demo.list') }}',
                columns: [{
                        className: 'dt-control',
                        orderable: false,
                        data: null,
                        defaultContent: ''
                    },
                    {
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex'
                    },
                    {
                        data: 'name',
                        name: 'name'
                    },
                    {
                        data: 'created_at',
                        name: 'created_at'
                    },
                    {
                        data: 'details',
                        visible: false
                    }
                ],
                order: [
                    [1, 'asc']
                ]
            });

            // Add event listener for opening and closing details
            $('#datatable tbody').on('click', 'td.dt-control', function() {
                let tr = $(this).closest('tr');
                let row = table.row(tr);

                if (row.child.isShown()) {
                    row.child.hide();
                    tr.removeClass('shown');
                } else {
                    row.child(format(row.data())).show();
                    tr.addClass('shown');
                }
            });
        });
    </script>
</body>

</html>
