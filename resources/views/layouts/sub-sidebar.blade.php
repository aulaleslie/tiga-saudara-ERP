@if(request()->routeIs('users*'))
    <div class="col-md-3 bg-light sidebar">
        <h2>Sidebar</h2>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="#">Link 1</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#">Link 2</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#">Link 3</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#">Link 4</a>
            </li>
        </ul>
    </div>
@endif

