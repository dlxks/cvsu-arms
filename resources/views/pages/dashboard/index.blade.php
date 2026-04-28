<?php

use Livewire\Component;

new class extends Component {
    //
};
?>

<div>
    @can('campuses.view')
    @endcan

    @can('departments.view')
    @endcan

    @can('schedules.assign')
    @endcan

    @can('faculty_schedules.view')
    @endcan
</div>
