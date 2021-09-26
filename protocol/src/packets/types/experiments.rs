use crate::{can_io, CanIo};

can_io! {
    struct ExperimentData {
        pub name: String,
        pub enabled: bool,
    }
}

impl CanIo for Vec<ExperimentData> {
    fn write(&self, vec: &mut Vec<u8>) {
        (self.len() as u32).write(vec);
        for exp_data in self {
            exp_data.write(vec);
        }
    }

    fn read(src: &[u8], offset: &mut usize) -> crate::Result<Self> {
        let len = u32::read(src, offset)?;
        let mut vec = Vec::with_capacity(len as usize);
        for _ in 0..len {
            vec.push(ExperimentData::read(src, offset)?);
        }
        Ok(vec)
    }
}
