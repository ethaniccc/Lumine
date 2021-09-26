use crate::can_io;
use crate::packets::types;

can_io! {
    struct ResourcePackStack {
        pub texture_pack_required: bool,
        pub behaviour_packs: Vec<types::StackResourcePack>,
        pub texture_packs: Vec<types::StackResourcePack>,
        pub base_game_version: String,
        pub experiments: Vec<types::ExperimentData>,
        pub experiments_previously_toggled: bool,
    }
}
